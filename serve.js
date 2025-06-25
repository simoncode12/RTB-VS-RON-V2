/**
 * AdStart RTB & RON Platform - Ad Serving Script
 * Version: 1.0.6
 * Last Updated: 2025-06-23 23:08:32
 * User: simoncode12
 */

(function() {
    // Get current script tag
    var currentScript = document.currentScript || (function() {
        var scripts = document.getElementsByTagName('script');
        return scripts[scripts.length - 1];
    })();
    
    // Check if required zone parameters exist
    if (typeof ad_zone_id === 'undefined') {
        console.error('AdStart: Missing required zone_id parameter');
        return;
    }
    
    // Use size from parameters or default to empty
    var ad_zone_width = ad_zone_width || '';
    var ad_zone_height = ad_zone_height || '';
    var ad_zone_type = ad_zone_type || 'banner';
    
    // Create a unique ID for this ad instance
    var adContainerId = 'adstart-ad-' + Math.random().toString(36).substr(2, 9);
    
    // Create ad container with proper size
    var adContainer = document.createElement('div');
    adContainer.id = adContainerId;
    adContainer.className = 'adstart-ad-container';
    
    // Set dimensions if available
    if (ad_zone_width && ad_zone_height) {
        adContainer.style.width = ad_zone_width + 'px';
        adContainer.style.height = ad_zone_height + 'px';
    }
    
    adContainer.style.overflow = 'hidden';
    adContainer.style.position = 'relative';
    adContainer.style.boxSizing = 'border-box';
    adContainer.style.display = 'flex';
    adContainer.style.justifyContent = 'center';
    adContainer.style.alignItems = 'center';
    
    // Insert container into DOM
    currentScript.parentNode.insertBefore(adContainer, currentScript);
    
    // Function to properly execute scripts in HTML
    function loadHTML(element, html) {
        // Create a temporary div to parse the HTML
        var temp = document.createElement('div');
        temp.innerHTML = html;
        
        // First append all non-script elements
        var children = temp.children;
        var scripts = [];
        
        while (children.length > 0) {
            var child = children[0];
            if (child.tagName === 'SCRIPT') {
                // Save scripts for later execution
                scripts.push(child);
                element.appendChild(child.cloneNode(true));
                temp.removeChild(child);
            } else {
                element.appendChild(child);
            }
        }
        
        // Then execute scripts in order
        scripts.forEach(function(script) {
            try {
                var newScript = document.createElement('script');
                Array.from(script.attributes).forEach(function(attr) {
                    newScript.setAttribute(attr.name, attr.value);
                });
                newScript.textContent = script.textContent;
                element.appendChild(newScript);
            } catch (e) {
                console.error('Error executing ad script:', e);
            }
        });
    }
    
    // Define the global function to receive ad content with containerID
    window['adstart_display_' + adContainerId] = function(adHtml, impressionUrl, clickUrl, dimensions) {
        console.log('AdStart: Received ad content for ' + adContainerId);
        
        // Apply dimensions if provided
        if (dimensions && typeof dimensions === 'object') {
            if (dimensions.width) adContainer.style.width = dimensions.width + 'px';
            if (dimensions.height) adContainer.style.height = dimensions.height + 'px';
        }
        
        // Clear container first
        adContainer.innerHTML = '';
        
        // Check if we have valid ad HTML
        if (!adHtml || typeof adHtml !== 'string') {
            console.error('AdStart: Invalid ad HTML content');
            displayFallbackAd();
            return;
        }
        
        try {
            // Properly load the HTML with scripts executed
            loadHTML(adContainer, adHtml);
            
            // Track impression
            if (impressionUrl) {
                var img = new Image();
                img.src = impressionUrl + (impressionUrl.indexOf('?') > -1 ? '&' : '?') + 't=' + new Date().getTime();
            }
            
            // Add click tracking with delay to allow ad scripts to initialize
            setTimeout(function() {
                if (clickUrl) {
                    var links = adContainer.getElementsByTagName('a');
                    for (var i = 0; i < links.length; i++) {
                        (function(link) {
                            var originalClick = link.onclick;
                            link.onclick = function(e) {
                                // Track click
                                var clickImg = new Image();
                                clickImg.src = clickUrl + (clickUrl.indexOf('?') > -1 ? '&' : '?') + 't=' + new Date().getTime();
                                
                                // Call original onclick if it exists
                                if (originalClick) {
                                    return originalClick.call(this, e);
                                }
                            };
                        })(links[i]);
                    }
                }
            }, 100);
            
            // Add GDPR compliance notice
            var gdprNotice = document.createElement('div');
            gdprNotice.style.position = 'absolute';
            gdprNotice.style.bottom = '0';
            gdprNotice.style.right = '0';
            gdprNotice.style.backgroundColor = 'rgba(0, 0, 0, 0.7)';
            gdprNotice.style.color = 'white';
            gdprNotice.style.fontSize = '8px';
            gdprNotice.style.padding = '2px 4px';
            gdprNotice.style.borderTopLeftRadius = '3px';
            gdprNotice.style.zIndex = '999999';
            gdprNotice.innerHTML = 'Ad';
            adContainer.appendChild(gdprNotice);
        } catch (e) {
            console.error('AdStart: Error displaying ad content', e);
            displayFallbackAd();
        }
    };
    
    // Display a fallback ad if no ad is available
    function displayFallbackAd() {
        adContainer.innerHTML = '<div style="width:100%; height:100%; display:flex; flex-direction:column; align-items:center; justify-content:center; text-align:center; color:#666; font-family:Arial, sans-serif; border:1px solid #ddd; background-color:#f9f9f9;">' +
            '<span style="font-size:12px;">Advertise Here</span>' +
            '<span style="font-size:10px; margin-top:5px;">RTB & RON Platform</span>' +
            '</div>';
    }
    
    // Ad request function
    function requestAd() {
        // Show loading indicator
        adContainer.innerHTML = '<div style="width:100%; height:100%; display:flex; align-items:center; justify-content:center;">' +
            '<div style="width:24px; height:24px; border:2px solid #ccc; border-radius:50%; border-top-color:#999; animation:adstart-spin 1s linear infinite;"></div></div>' +
            '<style>@keyframes adstart-spin{to{transform:rotate(360deg)}}</style>';
        
        // Create request URL WITH CONTAINER ID
        var adServerUrl = 'https://up.adstart.click/api/ad-serve.php?' +
            'zone_id=' + ad_zone_id +
            '&width=' + ad_zone_width +
            '&height=' + ad_zone_height +
            '&type=' + ad_zone_type +
            '&container=' + adContainerId +  // Essential: Pass container ID
            '&url=' + encodeURIComponent(window.location.href) +
            '&domain=' + encodeURIComponent(window.location.hostname) +
            '&referrer=' + encodeURIComponent(document.referrer || '') +
            '&ua=' + encodeURIComponent(navigator.userAgent) +
            '&t=' + new Date().getTime();
        
        // Log the request for debugging
        console.log('AdStart: Requesting ad from ' + adServerUrl);
        
        // Load the script
        var scriptTag = document.createElement('script');
        scriptTag.src = adServerUrl;
        scriptTag.onerror = function() {
            console.error('AdStart: Error loading ad script');
            displayFallbackAd();
        };
        
        document.head.appendChild(scriptTag);
        
        // Set a timeout to show fallback if no response
        setTimeout(function() {
            if (adContainer.querySelector('div[style*="adstart-spin"]')) {
                console.error('AdStart: Ad request timed out');
                displayFallbackAd();
            }
        }, 8000);
    }
    
    // Determine if the ad is in view and should be loaded
    function isElementInViewport(el) {
        var rect = el.getBoundingClientRect();
        return (
            rect.top >= -rect.height &&
            rect.left >= -rect.width &&
            rect.bottom <= (window.innerHeight || document.documentElement.clientHeight) + rect.height &&
            rect.right <= (window.innerWidth || document.documentElement.clientWidth) + rect.width
        );
    }
    
    // Check if the ad is in view when page loads and on scroll
    function checkAdVisibility() {
        if (isElementInViewport(adContainer) && !adContainer.hasAttribute('data-loaded')) {
            adContainer.setAttribute('data-loaded', 'true');
            requestAd();
        }
    }
    
    // Load ad when in viewport
    window.addEventListener('scroll', checkAdVisibility);
    window.addEventListener('resize', checkAdVisibility);
    window.addEventListener('load', checkAdVisibility);
    
    // Initial visibility check after a short delay
    setTimeout(checkAdVisibility, 100);
})();