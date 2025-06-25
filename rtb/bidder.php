<?php
/**
 * RTB Bidder Class
 * Current Date: 2025-06-24 00:47:30
 * Current User: simoncode12
 */

class RTBBidder {
    private $pdo;
    private $debug = false;
    private $test_mode = false;
    
    public function __construct($pdo, $debug = false) {
        $this->pdo = $pdo;
        $this->debug = $debug;
    }
    
    public function setTestMode($enabled) {
        $this->test_mode = $enabled;
        if ($this->debug && $enabled) {
            error_log("[RTB-DEBUG] Test mode enabled");
        }
    }
    
    public function processBidRequest($request) {
        try {
            $request_id = $request['id'];
            $impressions = $request['imp'];
            $site = $request['site'] ?? [];
            
            if (empty($impressions)) {
                if ($this->debug) error_log("[RTB-DEBUG] No impressions in request");
                return null;
            }
            
            // Website validation with special handling for test requests
            $website = null;
            $website_id = $site['id'] ?? null;
            $domain = $site['domain'] ?? null;
            
            if ($this->test_mode) {
                // Use a default test website for test requests
                $website = $this->getDefaultTestWebsite();
                
                if ($this->debug) error_log("[RTB-DEBUG] Using test website with ID: {$website['id']}");
            } else {
                // Standard website validation for non-test requests
                $website = $this->validateWebsite($website_id, $domain);
                
                if (!$website) {
                    if ($this->debug) error_log("[RTB-DEBUG] Website validation failed for ID: $website_id, Domain: $domain");
                    
                    // Try using the first active website as a fallback
                    $website = $this->getFirstActiveWebsite();
                    
                    if (!$website) {
                        if ($this->debug) error_log("[RTB-DEBUG] No active websites found in database");
                        return null;
                    }
                    
                    if ($this->debug) error_log("[RTB-DEBUG] Using fallback website with ID: {$website['id']}");
                }
            }
            
            $seatbids = [];
            
            foreach ($impressions as $imp) {
                $bids = $this->findMatchingCampaigns($imp, $request, $website);
                
                if (!empty($bids)) {
                    $seatbids[] = [
                        'bid' => $bids
                    ];
                }
            }
            
            if (empty($seatbids)) {
                if ($this->debug) error_log("[RTB-DEBUG] No matching campaigns found");
                return null; // No bid
            }
            
            return [
                'id' => $request_id,
                'seatbid' => $seatbids,
                'bidid' => uniqid('bidid_'),
                'cur' => 'USD'
            ];
            
        } catch (Exception $e) {
            error_log('Bidder Error: ' . $e->getMessage());
            return null;
        }
    }
    
    private function getDefaultTestWebsite() {
        // First, try to find a website with "test" in the name or domain
        try {
            $stmt = $this->pdo->query("
                SELECT w.id, w.domain, w.publisher_id, p.revenue_share
                FROM websites w
                JOIN publishers p ON w.publisher_id = p.id
                WHERE w.status = 'active' AND (w.domain LIKE '%test%' OR w.name LIKE '%test%')
                LIMIT 1
            ");
            
            $website = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($website) {
                return $website;
            }
        } catch (Exception $e) {
            error_log("[RTB-DEBUG] Error finding test website: " . $e->getMessage());
        }
        
        // If no test website found, use the first active website
        return $this->getFirstActiveWebsite();
    }
    
    private function getFirstActiveWebsite() {
        try {
            $stmt = $this->pdo->query("
                SELECT w.id, w.domain, w.publisher_id, p.revenue_share
                FROM websites w
                JOIN publishers p ON w.publisher_id = p.id
                WHERE w.status = 'active'
                ORDER BY w.id ASC
                LIMIT 1
            ");
            
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("[RTB-DEBUG] Error finding active website: " . $e->getMessage());
            return null;
        }
    }
    
    private function validateWebsite($website_id, $domain) {
        try {
            // Try to convert website_id to integer if possible
            if ($website_id && is_numeric($website_id)) {
                $website_id = intval($website_id);
            } else if ($website_id) {
                // For non-numeric IDs, try to find by domain instead
                if ($this->debug) error_log("[RTB-DEBUG] Non-numeric website ID: $website_id, using domain instead");
                $website_id = null;
            }
            
            if ($website_id) {
                $stmt = $this->pdo->prepare("
                    SELECT w.id, w.domain, w.publisher_id, p.revenue_share
                    FROM websites w
                    JOIN publishers p ON w.publisher_id = p.id
                    WHERE w.id = ? AND w.status = 'active'
                ");
                $stmt->execute([$website_id]);
                $website = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($website) {
                    return $website;
                }
            }
            
            if ($domain) {
                // Try exact domain match
                $stmt = $this->pdo->prepare("
                    SELECT w.id, w.domain, w.publisher_id, p.revenue_share
                    FROM websites w
                    JOIN publishers p ON w.publisher_id = p.id
                    WHERE w.domain = ? AND w.status = 'active'
                ");
                $stmt->execute([$domain]);
                $website = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($website) {
                    return $website;
                }
                
                // Try with and without www. prefix
                if (strpos($domain, 'www.') === 0) {
                    $alt_domain = substr($domain, 4);
                } else {
                    $alt_domain = 'www.' . $domain;
                }
                
                $stmt = $this->pdo->prepare("
                    SELECT w.id, w.domain, w.publisher_id, p.revenue_share
                    FROM websites w
                    JOIN publishers p ON w.publisher_id = p.id
                    WHERE w.domain = ? AND w.status = 'active'
                ");
                $stmt->execute([$alt_domain]);
                $website = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($website) {
                    return $website;
                }
            }
            
            return null;
            
        } catch (Exception $e) {
            error_log('Website validation error: ' . $e->getMessage());
            return null;
        }
    }
    
    private function findMatchingCampaigns($imp, $request, $website) {
        $bids = [];
        
        // Extract impression details
        $imp_id = $imp['id'];
        $banner = $imp['banner'] ?? null;
        $video = $imp['video'] ?? null;
        
        if (!$banner && !$video) {
            if ($this->debug) error_log("[RTB-DEBUG] No banner or video object in impression");
            return $bids;
        }
        
        // Get dimensions
        if ($banner) {
            $width = $banner['w'] ?? 0;
            $height = $banner['h'] ?? 0;
        } elseif ($video) {
            $width = $video['w'] ?? 0;
            $height = $video['h'] ?? 0;
        }
        
        if ($width == 0 || $height == 0) {
            if ($this->debug) error_log("[RTB-DEBUG] Invalid dimensions: {$width}x{$height}");
            return $bids;
        }
        
        // Extract targeting data from request
        $country = $this->extractCountry($request);
        $device_type = $this->extractDeviceType($request);
        $browser = $this->extractBrowser($request);
        $os = $this->extractOS($request);
        
        if ($this->debug) {
            error_log("[RTB-DEBUG] Targeting: Country=$country, Device=$device_type, Browser=$browser, OS=$os");
            error_log("[RTB-DEBUG] Looking for creatives of size: {$width}x{$height}");
        }
        
        // Find matching RTB campaigns
        $rtb_campaigns = $this->getMatchingRTBCampaigns($width, $height, $country, $device_type, $browser, $os);
        
        // Find matching RON campaigns
        $ron_campaigns = $this->getMatchingRONCampaigns($width, $height, $country, $device_type, $browser, $os);
        
        // Combine and sort by bid amount
        $all_campaigns = array_merge($rtb_campaigns, $ron_campaigns);
        
        if ($this->debug) {
            error_log("[RTB-DEBUG] Found " . count($rtb_campaigns) . " RTB campaigns and " . count($ron_campaigns) . " RON campaigns");
        }
        
        // For test mode or empty results, create a test campaign if needed
        if (($this->test_mode || empty($all_campaigns)) && $this->debug) {
            $test_campaign = $this->createTestCampaign($width, $height);
            if ($test_campaign) {
                $all_campaigns[] = $test_campaign;
                error_log("[RTB-DEBUG] Added test campaign");
            }
        }
        
        // Sort by bid amount (highest first)
        usort($all_campaigns, function($a, $b) {
            return $b['bid_amount'] <=> $a['bid_amount'];
        });
        
        // Take the highest bidder
        if (!empty($all_campaigns)) {
            $winning_campaign = $all_campaigns[0];
            
            // Create bid response
            $bid = $this->createBidResponse($imp_id, $winning_campaign, $website, $request);
            
            if ($bid) {
                $bids[] = $bid;
                
                // Log the bid
                $this->logBid($request['id'], $winning_campaign, $imp, $request, $website);
                
                if ($this->debug) {
                    error_log("[RTB-DEBUG] Successful bid created for campaign: {$winning_campaign['campaign_id']}, type: {$winning_campaign['campaign_type']}, bid: \${$winning_campaign['bid_amount']}");
                }
            }
        }
        
        return $bids;
    }
    
    private function createTestCampaign($width, $height) {
        return [
            'campaign_id' => 999999,
            'creative_id' => 999999,
            'name' => 'Test Campaign',
            'campaign_type' => 'test',
            'creative_type' => 'html5',
            'width' => $width,
            'height' => $height,
            'bid_amount' => 0.01,
            'html_content' => '<div style="width:' . $width . 'px;height:' . $height . 'px;background:#f0f0f0;display:flex;justify-content:center;align-items:center;border:1px solid #ccc;font-family:Arial,sans-serif;font-size:13px;"><div>Test Ad (' . $width . 'x' . $height . ')</div></div>',
            'image_url' => null,
            'video_url' => null,
            'click_url' => 'https://up.adstart.click'
        ];
    }
    
    // [Rest of the RTBBidder class methods remain the same as in your previous implementation]
    
    private function getMatchingRTBCampaigns($width, $height, $country, $device_type, $browser, $os) {
        $sql = "
            SELECT c.*, cr.*, c.id as campaign_id, cr.id as creative_id, cr.bid_amount, 'rtb' as campaign_type
            FROM campaigns c
            JOIN creatives cr ON c.id = cr.campaign_id
            WHERE c.type = 'rtb' 
            AND c.status = 'active' 
            AND cr.status = 'active'
            AND cr.width = ? 
            AND cr.height = ?
            AND (c.start_date IS NULL OR c.start_date <= CURDATE())
            AND (c.end_date IS NULL OR c.end_date >= CURDATE())
        ";
        
        $params = [(int)$width, (int)$height];
        
        // Add targeting filters
        if ($country) {
            $sql .= " AND (c.target_countries IS NULL OR JSON_CONTAINS(c.target_countries, ?))";
            $params[] = json_encode($country);
        }
        
        if ($device_type) {
            $sql .= " AND (c.target_devices IS NULL OR JSON_CONTAINS(c.target_devices, ?))";
            $params[] = json_encode($device_type);
        }
        
        $sql .= " ORDER BY cr.bid_amount DESC";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    private function getMatchingRONCampaigns($width, $height, $country, $device_type, $browser, $os) {
        $sql = "
            SELECT c.*, cr.*, c.id as campaign_id, cr.id as creative_id, cr.bid_amount, 'ron' as campaign_type
            FROM campaigns c
            JOIN creatives cr ON c.id = cr.campaign_id
            WHERE c.type = 'ron' 
            AND c.status = 'active' 
            AND cr.status = 'active'
            AND cr.width = ? 
            AND cr.height = ?
            AND (c.start_date IS NULL OR c.start_date <= CURDATE())
            AND (c.end_date IS NULL OR c.end_date >= CURDATE())
        ";
        
        $params = [(int)$width, (int)$height];
        
        // Add targeting filters (same as RTB)
        if ($country) {
            $sql .= " AND (c.target_countries IS NULL OR JSON_CONTAINS(c.target_countries, ?))";
            $params[] = json_encode($country);
        }
        
        if ($device_type) {
            $sql .= " AND (c.target_devices IS NULL OR JSON_CONTAINS(c.target_devices, ?))";
            $params[] = json_encode($device_type);
        }
        
        $sql .= " ORDER BY cr.bid_amount DESC";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    private function createBidResponse($imp_id, $campaign, $website, $request) {
        try {
            // Generate bid ID
            $bid_id = uniqid('bid_');
            
            // Create ad markup based on creative type
            $adm = $this->createAdMarkup($campaign);
            
            if (!$adm) {
                return null;
            }
            
            // Create win notification URL with revenue sharing info
            $nurl = 'https://up.adstart.click/rtb/win.php?bid_id=' . $bid_id . 
                   '&campaign_id=' . $campaign['campaign_id'] . 
                   '&creative_id=' . $campaign['creative_id'] . 
                   '&website_id=' . $website['id'] .
                   '&publisher_id=' . $website['publisher_id'] .
                   '&revenue_share=' . $website['revenue_share'] .
                   '&price=${AUCTION_PRICE}';
            
            return [
                'id' => $bid_id,
                'impid' => $imp_id,
                'price' => (float)$campaign['bid_amount'],
                'adm' => $adm,
                'nurl' => $nurl,
                'cid' => (string)$campaign['campaign_id'],
                'crid' => (string)$campaign['creative_id'],
                'w' => (int)$campaign['width'],
                'h' => (int)$campaign['height'],
                'iurl' => $campaign['image_url'] ?? null,
                'adomain' => ["adstart.click"],
                'ext' => [
                    'campaign_type' => $campaign['campaign_type']
                ]
            ];
            
        } catch (Exception $e) {
            error_log('Create bid response error: ' . $e->getMessage());
            return null;
        }
    }
    
    private function createAdMarkup($campaign) {
        switch ($campaign['creative_type']) {
            case 'image':
                return $this->createImageAdMarkup($campaign);
            case 'video':
                return $this->createVideoAdMarkup($campaign);
            case 'html5':
            case 'third_party':
                return $this->createHtmlAdMarkup($campaign);
            default:
                return null;
        }
    }
    
    private function createImageAdMarkup($campaign) {
        if ($campaign['image_url'] && $campaign['click_url']) {
            // HTML format for image ads (easier to handle)
            return '<a href="' . htmlspecialchars($campaign['click_url']) . '" target="_blank" rel="nofollow noopener">' .
                   '<img src="' . htmlspecialchars($campaign['image_url']) . '" ' .
                   'width="' . $campaign['width'] . '" height="' . $campaign['height'] . '" ' .
                   'style="border:0; display:block;" alt="Advertisement">' .
                   '</a>';
        }
        return null;
    }
    
    private function createVideoAdMarkup($campaign) {
        if ($campaign['video_url'] && $campaign['click_url']) {
            // VAST XML format for video ads
            return '<VAST version="3.0">' .
                   '<Ad id="' . $campaign['creative_id'] . '">' .
                   '<InLine>' .
                   '<AdSystem>AdStart</AdSystem>' .
                   '<AdTitle>' . htmlspecialchars($campaign['name']) . '</AdTitle>' .
                   '<Impression><![CDATA[https://up.adstart.click/api/impression-track.php?campaign_id=' . $campaign['campaign_id'] . '&creative_id=' . $campaign['creative_id'] . ']]></Impression>' .
                   '<Creatives>' .
                   '<Creative>' .
                   '<Linear>' .
                   '<Duration>00:00:30</Duration>' .
                   '<VideoClicks>' .
                   '<ClickThrough><![CDATA[' . $campaign['click_url'] . ']]></ClickThrough>' .
                   '</VideoClicks>' .
                   '<MediaFiles>' .
                   '<MediaFile delivery="progressive" type="video/mp4" width="' . $campaign['width'] . '" height="' . $campaign['height'] . '"><![CDATA[' . $campaign['video_url'] . ']]></MediaFile>' .
                   '</MediaFiles>' .
                   '</Linear>' .
                   '</Creative>' .
                   '</Creatives>' .
                   '</InLine>' .
                   '</Ad>' .
                   '</VAST>';
        }
        return null;
    }
    
    private function createHtmlAdMarkup($campaign) {
        if ($campaign['html_content']) {
            return $campaign['html_content'];
        } elseif ($campaign['image_url'] && $campaign['click_url']) {
            // HTML format for image ads with tracking
            $click_tracking = 'https://up.adstart.click/api/click-track.php?campaign_id=' . $campaign['campaign_id'] . '&creative_id=' . $campaign['creative_id'];
            $impression_tracking = 'https://up.adstart.click/api/impression-track.php?campaign_id=' . $campaign['campaign_id'] . '&creative_id=' . $campaign['creative_id'];
            
            return '<a href="' . htmlspecialchars($click_tracking) . '" target="_blank" onclick="window.open(\'' . htmlspecialchars($campaign['click_url']) . '\');">' .
                   '<img width="' . $campaign['width'] . '" height="' . $campaign['height'] . '" src="' . htmlspecialchars($campaign['image_url']) . '" border="0" style="display:block;"></a>' .
                   '<img src="' . htmlspecialchars($impression_tracking) . '" width="1" height="1" border="0" style="display:none;" />';
        }
        return null;
    }
    
    private function extractCountry($request) {
        $device = $request['device'] ?? [];
        $geo = $device['geo'] ?? [];
        return $geo['country'] ?? null;
    }
    
    private function extractDeviceType($request) {
        $device = $request['device'] ?? [];
        $devicetype = $device['devicetype'] ?? null;
        
        if ($devicetype !== null) {
            // OpenRTB devicetype values
            switch ($devicetype) {
                case 1: return 'mobile';
                case 2: return 'pc';
                case 3: return 'tv';
                case 4: return 'phone';
                case 5: return 'tablet';
                case 6: return 'connected_device';
                case 7: return 'set_top_box';
            }
        }
        
        // Simple device detection based on user agent
        $ua = strtolower($device['ua'] ?? '');
        
        if (strpos($ua, 'mobile') !== false || strpos($ua, 'android') !== false || strpos($ua, 'iphone') !== false) {
            return 'mobile';
        } elseif (strpos($ua, 'tablet') !== false || strpos($ua, 'ipad') !== false) {
            return 'tablet';
        } else {
            return 'desktop';
        }
    }
    
    private function extractBrowser($request) {
        $device = $request['device'] ?? [];
        $ua = strtolower($device['ua'] ?? '');
        
        if (strpos($ua, 'chrome') !== false) return 'chrome';
        if (strpos($ua, 'firefox') !== false) return 'firefox';
        if (strpos($ua, 'safari') !== false) return 'safari';
        if (strpos($ua, 'edge') !== false) return 'edge';
        if (strpos($ua, 'opera') !== false) return 'opera';
        
        return 'unknown';
    }
    
    private function extractOS($request) {
        $device = $request['device'] ?? [];
        $os = $device['os'] ?? null;
        
        if ($os) return strtolower($os);
        
        $ua = strtolower($device['ua'] ?? '');
        
        if (strpos($ua, 'windows') !== false) return 'windows';
        if (strpos($ua, 'macintosh') !== false || strpos($ua, 'mac os') !== false) return 'macos';
        if (strpos($ua, 'linux') !== false) return 'linux';
        if (strpos($ua, 'android') !== false) return 'android';
        if (strpos($ua, 'ios') !== false || strpos($ua, 'iphone') !== false || strpos($ua, 'ipad') !== false) return 'ios';
        
        return 'unknown';
    }
    
    private function logBid($request_id, $campaign, $imp, $request, $website) {
        try {
            $device = $request['device'] ?? [];
            $geo = $device['geo'] ?? [];
            
            // Don't log test campaigns to database
            if ($campaign['campaign_type'] == 'test') {
                return;
            }
            
            $stmt = $this->pdo->prepare("
                INSERT INTO bid_logs (
                    request_id, campaign_id, creative_id, zone_id,
                    bid_amount, win_price, impression_id,
                    user_agent, ip_address, country, device_type, browser, os,
                    status, created_at
                ) VALUES (
                    ?, ?, ?, ?,
                    ?, NULL, ?,
                    ?, ?, ?, ?, ?, ?,
                    'bid', NOW()
                )
            ");
            
            $stmt->execute([
                $request_id,
                $campaign['campaign_id'],
                $campaign['creative_id'],
                0, // Zone ID (not available in RTB context)
                $campaign['bid_amount'],
                uniqid('imp_'),
                $device['ua'] ?? '',
                $device['ip'] ?? '',
                $geo['country'] ?? '',
                $this->extractDeviceType($request),
                $this->extractBrowser($request),
                $this->extractOS($request)
            ]);
            
        } catch (Exception $e) {
            error_log('Log bid error: ' . $e->getMessage());
        }
    }
}
?>