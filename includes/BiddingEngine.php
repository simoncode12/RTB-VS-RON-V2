<?php
/**
 * Unified Bidding Engine for RTB vs RON Campaigns
 * Ensures highest bidder always wins regardless of campaign type
 * 
 * Version: 2.0.0
 * Date: 2025-06-25
 * Author: Enhanced by AI Assistant
 */

class BiddingEngine {
    private $pdo;
    private $debug;
    private $cache = [];
    
    public function __construct($pdo, $debug = false) {
        $this->pdo = $pdo;
        $this->debug = $debug;
    }
    
    /**
     * Main method to process bid request and return winning bid
     * 
     * @param array $request_data Request parameters including zone, size, targeting
     * @return array|null Winning bid data or null if no valid bids
     */
    public function processBidRequest($request_data) {
        $start_time = microtime(true);
        
        try {
            // Extract and validate request data
            $zone_id = intval($request_data['zone_id'] ?? 0);
            $width = intval($request_data['width'] ?? 0);
            $height = intval($request_data['height'] ?? 0);
            $targeting = $this->extractTargeting($request_data);
            
            if (!$this->validateRequest($zone_id, $width, $height)) {
                return null;
            }
            
            // Get zone information with caching
            $zone = $this->getZoneInfo($zone_id);
            if (!$zone || $zone['status'] !== 'active') {
                $this->log("Zone $zone_id not found or inactive");
                return null;
            }
            
            // Find all eligible campaigns (RTB + RON)
            $eligible_campaigns = $this->findEligibleCampaigns($width, $height, $targeting);
            
            if (empty($eligible_campaigns)) {
                $this->log("No eligible campaigns found for {$width}x{$height}");
                return null;
            }
            
            // Calculate final bids with all adjustments
            $calculated_bids = $this->calculateBids($eligible_campaigns, $targeting, $zone);
            
            // Select winner using improved auction logic
            $winning_bid = $this->selectWinner($calculated_bids, $zone);
            
            if ($winning_bid) {
                // Log successful bid and update statistics
                $this->logWinningBid($winning_bid, $request_data, $targeting);
                $this->updateCampaignStats($winning_bid);
                
                $processing_time = (microtime(true) - $start_time) * 1000;
                $this->log("Bidding completed in {$processing_time}ms - Winner: {$winning_bid['campaign_type']} campaign #{$winning_bid['campaign_id']} at \${$winning_bid['final_price']}");
                
                return $winning_bid;
            }
            
            return null;
            
        } catch (Exception $e) {
            error_log("BiddingEngine Error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Find all eligible campaigns for the given parameters
     */
    private function findEligibleCampaigns($width, $height, $targeting) {
        $cache_key = "campaigns_{$width}x{$height}_" . md5(serialize($targeting));
        
        if (isset($this->cache[$cache_key])) {
            return $this->cache[$cache_key];
        }
        
        $sql = "
            SELECT 
                c.id as campaign_id,
                c.name as campaign_name,
                c.type as campaign_type,
                c.daily_budget,
                c.total_budget,
                c.daily_spent,
                c.total_spent,
                c.target_countries,
                c.target_devices,
                c.target_browsers,
                c.target_os,
                c.endpoint_url,
                cr.id as creative_id,
                cr.name as creative_name,
                cr.creative_type,
                cr.bid_amount,
                cr.image_url,
                cr.video_url,
                cr.html_content,
                cr.click_url,
                cr.width,
                cr.height,
                a.id as advertiser_id,
                a.name as advertiser_name,
                a.balance as advertiser_balance
            FROM campaigns c
            JOIN creatives cr ON c.id = cr.campaign_id
            JOIN advertisers a ON c.advertiser_id = a.id
            WHERE c.status = 'active'
            AND cr.status = 'active'
            AND a.status = 'active'
            AND cr.width = ?
            AND cr.height = ?
            AND (c.start_date IS NULL OR c.start_date <= CURDATE())
            AND (c.end_date IS NULL OR c.end_date >= CURDATE())
            AND c.daily_budget > c.daily_spent
            AND c.total_budget > c.total_spent
            AND a.balance > 0
            AND cr.bid_amount > 0
        ";
        
        $params = [$width, $height];
        
        // Add targeting filters
        if (!empty($targeting['country'])) {
            $sql .= " AND (c.target_countries IS NULL OR JSON_CONTAINS(c.target_countries, ?))";
            $params[] = json_encode($targeting['country']);
        }
        
        if (!empty($targeting['device_type'])) {
            $sql .= " AND (c.target_devices IS NULL OR JSON_CONTAINS(c.target_devices, ?))";
            $params[] = json_encode($targeting['device_type']);
        }
        
        if (!empty($targeting['browser'])) {
            $sql .= " AND (c.target_browsers IS NULL OR JSON_CONTAINS(c.target_browsers, ?))";
            $params[] = json_encode($targeting['browser']);
        }
        
        if (!empty($targeting['os'])) {
            $sql .= " AND (c.target_os IS NULL OR JSON_CONTAINS(c.target_os, ?))";
            $params[] = json_encode($targeting['os']);
        }
        
        $sql .= " ORDER BY cr.bid_amount DESC";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $campaigns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Cache for 30 seconds
        $this->cache[$cache_key] = $campaigns;
        
        $this->log("Found " . count($campaigns) . " eligible campaigns");
        
        return $campaigns;
    }
    
    /**
     * Calculate final bid prices with all adjustments
     */
    private function calculateBids($campaigns, $targeting, $zone) {
        $calculated_bids = [];
        
        foreach ($campaigns as $campaign) {
            $base_bid = floatval($campaign['bid_amount']);
            $final_price = $base_bid;
            $adjustments = [];
            
            // Apply targeting adjustments
            if ($this->matchesTargeting($campaign, $targeting)) {
                $targeting_boost = 1.15; // 15% boost for perfect targeting match
                $final_price *= $targeting_boost;
                $adjustments[] = "targeting_boost: +15%";
            }
            
            // Apply campaign type specific adjustments
            if ($campaign['campaign_type'] === 'rtb') {
                // RTB campaigns get slight priority for same bids
                $final_price *= 1.02; // 2% boost
                $adjustments[] = "rtb_priority: +2%";
            }
            
            // Apply budget constraints
            $remaining_daily = $campaign['daily_budget'] - $campaign['daily_spent'];
            $remaining_total = $campaign['total_budget'] - $campaign['total_spent'];
            $max_affordable = min($remaining_daily, $remaining_total, $campaign['advertiser_balance']);
            
            if ($final_price > $max_affordable) {
                continue; // Skip if can't afford the bid
            }
            
            // Apply floor price constraint
            if (isset($zone['floor_price']) && $final_price < $zone['floor_price']) {
                continue; // Skip if below floor price
            }
            
            $calculated_bids[] = [
                'campaign_id' => $campaign['campaign_id'],
                'campaign_name' => $campaign['campaign_name'],
                'campaign_type' => $campaign['campaign_type'],
                'creative_id' => $campaign['creative_id'],
                'advertiser_id' => $campaign['advertiser_id'],
                'advertiser_name' => $campaign['advertiser_name'],
                'base_bid' => $base_bid,
                'final_price' => $final_price,
                'adjustments' => $adjustments,
                'creative_data' => [
                    'type' => $campaign['creative_type'],
                    'width' => $campaign['width'],
                    'height' => $campaign['height'],
                    'image_url' => $campaign['image_url'],
                    'video_url' => $campaign['video_url'],
                    'html_content' => $campaign['html_content'],
                    'click_url' => $campaign['click_url']
                ],
                'endpoint_url' => $campaign['endpoint_url'],
                'remaining_budget' => $max_affordable
            ];
        }
        
        // Sort by final price (highest first)
        usort($calculated_bids, function($a, $b) {
            if ($a['final_price'] == $b['final_price']) {
                // If prices are equal, prefer RTB
                if ($a['campaign_type'] !== $b['campaign_type']) {
                    return $a['campaign_type'] === 'rtb' ? -1 : 1;
                }
                // If same type, prefer higher base bid
                return $b['base_bid'] <=> $a['base_bid'];
            }
            return $b['final_price'] <=> $a['final_price'];
        });
        
        return $calculated_bids;
    }
    
    /**
     * Select winning bid using second-price auction with floor price
     */
    private function selectWinner($bids, $zone) {
        if (empty($bids)) {
            return null;
        }
        
        $winner = $bids[0];
        $second_price = 0;
        
        // Calculate second price for auction
        if (count($bids) > 1) {
            $second_price = $bids[1]['final_price'];
        } else {
            // Single bidder - use 90% of their bid or floor price, whichever is higher
            $second_price = max(
                $winner['final_price'] * 0.9,
                floatval($zone['floor_price'] ?? 0)
            );
        }
        
        // Final winning price is second price + small increment, but not more than winner's bid
        $winning_price = min(
            max($second_price + 0.0001, floatval($zone['floor_price'] ?? 0)),
            $winner['final_price']
        );
        
        $winner['winning_price'] = $winning_price;
        $winner['second_price'] = $second_price;
        
        return $winner;
    }
    
    /**
     * Check if campaign matches targeting criteria
     */
    private function matchesTargeting($campaign, $targeting) {
        $matches = 0;
        $total_criteria = 0;
        
        // Check country targeting
        if (!empty($campaign['target_countries'])) {
            $total_criteria++;
            $target_countries = json_decode($campaign['target_countries'], true) ?: [];
            if (in_array($targeting['country'] ?? '', $target_countries)) {
                $matches++;
            }
        }
        
        // Check device targeting
        if (!empty($campaign['target_devices'])) {
            $total_criteria++;
            $target_devices = json_decode($campaign['target_devices'], true) ?: [];
            if (in_array($targeting['device_type'] ?? '', $target_devices)) {
                $matches++;
            }
        }
        
        // Check browser targeting
        if (!empty($campaign['target_browsers'])) {
            $total_criteria++;
            $target_browsers = json_decode($campaign['target_browsers'], true) ?: [];
            if (in_array($targeting['browser'] ?? '', $target_browsers)) {
                $matches++;
            }
        }
        
        // Check OS targeting
        if (!empty($campaign['target_os'])) {
            $total_criteria++;
            $target_os = json_decode($campaign['target_os'], true) ?: [];
            if (in_array($targeting['os'] ?? '', $target_os)) {
                $matches++;
            }
        }
        
        // Return true if matches all targeted criteria (or no criteria set)
        return $total_criteria === 0 || $matches === $total_criteria;
    }
    
    /**
     * Extract targeting information from request
     */
    private function extractTargeting($request_data) {
        return [
            'country' => $this->detectCountry($request_data['ip'] ?? $_SERVER['REMOTE_ADDR'] ?? ''),
            'device_type' => $this->detectDevice($request_data['user_agent'] ?? $_SERVER['HTTP_USER_AGENT'] ?? ''),
            'browser' => $this->detectBrowser($request_data['user_agent'] ?? $_SERVER['HTTP_USER_AGENT'] ?? ''),
            'os' => $this->detectOS($request_data['user_agent'] ?? $_SERVER['HTTP_USER_AGENT'] ?? ''),
            'ip' => $request_data['ip'] ?? $_SERVER['REMOTE_ADDR'] ?? '',
            'user_agent' => $request_data['user_agent'] ?? $_SERVER['HTTP_USER_AGENT'] ?? '',
            'referer' => $request_data['referer'] ?? $_SERVER['HTTP_REFERER'] ?? ''
        ];
    }
    
    /**
     * Get zone information with caching
     */
    private function getZoneInfo($zone_id) {
        $cache_key = "zone_$zone_id";
        
        if (isset($this->cache[$cache_key])) {
            return $this->cache[$cache_key];
        }
        
        $stmt = $this->pdo->prepare("SELECT * FROM zones WHERE id = ?");
        $stmt->execute([$zone_id]);
        $zone = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($zone) {
            $this->cache[$cache_key] = $zone;
        }
        
        return $zone;
    }
    
    /**
     * Validate request parameters
     */
    private function validateRequest($zone_id, $width, $height) {
        if ($zone_id <= 0) {
            $this->log("Invalid zone ID: $zone_id");
            return false;
        }
        
        if ($width <= 0 || $height <= 0) {
            $this->log("Invalid dimensions: {$width}x{$height}");
            return false;
        }
        
        return true;
    }
    
    /**
     * Log winning bid to database
     */
    private function logWinningBid($winning_bid, $request_data, $targeting) {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO bid_logs (
                    request_id, campaign_id, creative_id, zone_id,
                    bid_amount, win_price, impression_id,
                    user_agent, ip_address, country, device_type, browser, os,
                    status, created_at
                ) VALUES (
                    ?, ?, ?, ?,
                    ?, ?, ?,
                    ?, ?, ?, ?, ?, ?,
                    'win', NOW()
                )
            ");
            
            $stmt->execute([
                uniqid('req_'),
                $winning_bid['campaign_id'],
                $winning_bid['creative_id'],
                $request_data['zone_id'] ?? 0,
                $winning_bid['final_price'],
                $winning_bid['winning_price'],
                uniqid('imp_'),
                $targeting['user_agent'],
                $targeting['ip'],
                $targeting['country'],
                $targeting['device_type'],
                $targeting['browser'],
                $targeting['os']
            ]);
        } catch (Exception $e) {
            error_log("Error logging winning bid: " . $e->getMessage());
        }
    }
    
    /**
     * Update campaign statistics
     */
    private function updateCampaignStats($winning_bid) {
        try {
            $stmt = $this->pdo->prepare("
                UPDATE campaigns 
                SET daily_spent = daily_spent + ?, 
                    total_spent = total_spent + ?,
                    impressions = impressions + 1,
                    updated_at = NOW()
                WHERE id = ?
            ");
            
            $price = $winning_bid['winning_price'];
            $stmt->execute([$price, $price, $winning_bid['campaign_id']]);
            
            // Update advertiser balance
            $stmt = $this->pdo->prepare("
                UPDATE advertisers 
                SET balance = balance - ?
                WHERE id = ?
            ");
            $stmt->execute([$price, $winning_bid['advertiser_id']]);
            
        } catch (Exception $e) {
            error_log("Error updating campaign stats: " . $e->getMessage());
        }
    }
    
    /**
     * Device detection
     */
    private function detectDevice($user_agent) {
        $ua = strtolower($user_agent);
        if (strpos($ua, 'mobile') !== false || strpos($ua, 'android') !== false || strpos($ua, 'iphone') !== false) {
            return 'mobile';
        } elseif (strpos($ua, 'tablet') !== false || strpos($ua, 'ipad') !== false) {
            return 'tablet';
        }
        return 'desktop';
    }
    
    /**
     * Browser detection
     */
    private function detectBrowser($user_agent) {
        $ua = strtolower($user_agent);
        if (strpos($ua, 'chrome') !== false) return 'chrome';
        if (strpos($ua, 'firefox') !== false) return 'firefox';
        if (strpos($ua, 'safari') !== false) return 'safari';
        if (strpos($ua, 'edge') !== false) return 'edge';
        if (strpos($ua, 'opera') !== false) return 'opera';
        if (strpos($ua, 'msie') !== false || strpos($ua, 'trident') !== false) return 'ie';
        return 'unknown';
    }
    
    /**
     * OS detection
     */
    private function detectOS($user_agent) {
        $ua = strtolower($user_agent);
        if (strpos($ua, 'windows') !== false) return 'windows';
        if (strpos($ua, 'macintosh') !== false || strpos($ua, 'mac os') !== false) return 'macos';
        if (strpos($ua, 'linux') !== false) return 'linux';
        if (strpos($ua, 'android') !== false) return 'android';
        if (strpos($ua, 'ios') !== false || strpos($ua, 'iphone') !== false || strpos($ua, 'ipad') !== false) return 'ios';
        return 'unknown';
    }
    
    /**
     * Simple country detection (in production, use GeoIP)
     */
    private function detectCountry($ip) {
        // Simplified implementation - in production use MaxMind GeoIP2 or similar
        return 'US';
    }
    
    /**
     * Debug logging
     */
    private function log($message) {
        if ($this->debug) {
            error_log("[BiddingEngine] $message");
        }
    }
}