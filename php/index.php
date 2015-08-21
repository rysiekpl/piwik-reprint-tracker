<?php

# 
# A simple Piwik-based reprint tracker
# 
# Serves an image (using default image or campaign-based image if supplied)
# upon request, and uses PIwik PHP API to count a visit, with a campaign name
# set either based on the requested image filename (if using nice URLs like
# /reprints/campaign-name.png), or based on GET param 'campaign'.
# 
# Campaign image is configured by simply putting an image with a filename
# matching the campaign in the images/ directory. If no image is found,
# "default.png" image is used; if it is also not found, a 503 Service
# Unavailable error is returned and the visit is *not* counted.
#
# Piwik URL, token etc. are configurable in config.php file in the main
# directory of the project. An example config is provided in config-example.php

# config -- it has to exist
require_once('..' . DIRECTORY_SEPARATOR . 'config.php');

# piwik tracker -- it also has to exist
require_once('..' . DIRECTORY_SEPARATOR . 'piwik-php-tracker' . DIRECTORY_SEPARATOR . 'PiwikTracker.php');


# handle the tracking
function track($campaign, $keyword) {
    # Piwik URL
    PiwikTracker::$URL = $GLOBALS['config']['piwik_url'];

    # PiwikTracker
    $piwik = new PiwikTracker($GLOBALS['config']['idsite']);

    # required for setIP()
    $piwik->setTokenAuth($GLOBALS['config']['piwik_token']);

    # IP of the remote client
    $piwik->setIP(
        $_SERVER['REMOTE_ADDR']
    );
    # User Agent String of the client
    $piwik->setUserAgent(
        $_SERVER['HTTP_USER_AGENT']
    );

    # get the schema
    $schema = 'https://';
    if (empty($_SERVER['HTTPS']) or ($_SERVER['HTTPS'] === 'off')) {
        $schema = 'http://';
    }
    
    # set the tracked URL
    $piwik->setUrl($schema . $_SERVER['SERVER_NAME'] . $_SERVER['REQUEST_URI']);

    # referer
    $piwik->setUrlReferrer($_SERVER['HTTP_REFERER']);
    
    # set attribution info (keyword, campaign, et al)
    # http://developer.piwik.org/api-reference/PHP-Piwik-Tracker#setattributioninfo
    # 0 - campaign name
    # 1 - keyword
    # 2 - timestamp
    # 3 - referrer
    $piwik->setAttributionInfo()
        json_encode(array(
            0 => $campaign,
            1 => $keyword,
            2 => 0,
            3 => $_SERVER['HTTP_REFERER']
        ))
    );
    
    # do track the page view
    if (empty($keyword)) $keyword = 'none';
    $piwik->doTrackPageView("Reprint Tracker for: $campaign (keyword: $keyword)");
}


# handle the headers
function headers($campaign_image) {
    
    # image- adn file-related headers
    header('Content-Length: ' . filesize($campaign_image));
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    header('Content-Type: ' . finfo_file($finfo, $campaign_image));
    finfo_close($finfo);

    
    # what we need is no caching
    header('Cache-Control: no-cache');
    header('Pragma: no-cache');
    header('Expires: Sat, 26 Jul 1997 05:00:00 GMT');
}


# getting the campaign data
function campaign_data() {
    
    # do we have an explicit filename in the request URI?
    $r_uri = end(explode('/', $_SERVER['REQUEST_URI']));
    
    # is it an image URI?
    # form: campaign_name.keyword.ext or just campaign_name.ext
    $matches = array();
    if (preg_match('^([a-zA-Z1-9\-_]+)(\.[a-zA-Z1-9\-_]+)?\.(' . join('|', $GLOBALS['config']['image_extensions']) . ')$', $r_uri, $matches) === 1) {
    
        # let's get campaign and keywords out of it!
        # campaign is easy, it is non-optional in the regex above
        $GLOBALS['campaign'] = $matches[1];
        
        # now, keyword... is a bit different
        if (isset($matches[2]) and ! empty ($matches[2]))
            # remember to remove the dot
            $GLOBALS['keyword'] = trim($matches[2], '.');
    
    # no match; is it just a regular GET with params?
    } else {
        
        # do we have a campaign?
        if (isset($_GET['pk_campaign']) and ! empty($_GET['pk_campaign'])) {
            $GLOBALS['campaign'] = $_GET['pk_campaign'];
        
        } # otherwise, the default one will be used anyway
        
        # do we have a keyword?
        if (isset($_GET['pk_keyword']) and ! empty($_GET['pk_keyword']))
            $GLOBALS['keyword'] = $_GET['pk_keyword'];
    }
}


# establishing the URI of the campaign image
function campaign_image($campaign, $exts) {
    #
    # either we have a $campaing.$ext image
    # or we're using default.$ext image
    # 
    # if none of these exist, that's a 503 Service Unavailable
    
    $imgdir = '..' . DIRECTORY_SEPARATOR . 'images' . DIRECTORY_SEPARATOR;
    
    # $campaign-based image?
    for ($exts as $ext) {
        if (file_exists("${imgdir}$campaign.$ext") and is_readable("${imgdir}$campaign.$ext"))
            return "${imgdir}$campaign.$ext";
    }
    
    # default.$ext?
    for ($exts as $ext) {
        if (file_exists("${imgdir}default.$ext") and is_readable("${imgdir}default.$ext"))
            return "${imgdir}default.$ext";
    }
    
    # no file found! abort abort abort!
    header("HTTP/1.0 503 Service Unavailable");
    exit(0);
}

# default campaign and keyword
$GLOBALS['campaign'] = $GLOBALS['config']['default_campaign'];
$GLOBALS['keyword'] = "";

# get the actual campaign data
campaign_data();

# build campaign image uri
$GLOBALS['campaign_image'] = campaign_image($GLOBALS['campaign'], $GLOBALS['config']['image_extensions']);

# track the page view!
track($GLOBALS['campaign'], $GLOBALS['keyword']);

# set the headers
headers($GLOBALS['campaign_image']);

# return the image
readfile($GLOBALS['campaign_image']);