<?php
/**
 * Open Source Social Network
 *
 * @package Open Source Social Network
 * @author    Open Social Website Core Team <info@softlab24.com>, Michael Lindenau
 * @copyright 2014-2017 SOFTLAB24 LIMITED, 2017 Michael Lindenau
 * @license   Open Source Social Network License (OSSN LICENSE)  http://www.opensource-socialnetwork.org/licence
 * @link      https://www.opensource-socialnetwork.org/
 */
 
/**
 * Library modifed for Ossn, Original code is from Cash Costello.
 * Functions to parse urls and create embed object. Supports oembed
 * open graph and twitter tags.
 *
 * @package Embed Video Library
 * @license http://www.gnu.org/licenses/gpl.html GNU Public License version 2
 * @author Cash Costello, Michael Lindenau
 * @copyright Cash Costello 2009-2011, Michael Lindenau 2017
 * *
 */

/**
 * Public API for library
 *
 * @param string $url either the url or embed code
 * @param integer $guid unique identifier of the widget
 * @param integer $objectwidth override the admin set default width
 * @return string html video div with object embed code or error message
 */
function ossn_embed_create_embed_object($url, $guid, $objectwidth=0) {

	if (!isset($url)) {
		return false;
	}
	$preserved_url = linkify($url);
	// check oembed
	$embed_code = ossn_embed_get_oembed($url, $guid, $objectwidth);
	if (! $embed_code) {
	    return false;
	} else {
	    return $preserved_url . $embed_code;
	}
}

/**
 * Curls the content of an url for inspection.
 * @param string $url to get the content from.
 * @param string $mode can be all, header_only (refers to the http header) 
 * or head_only (refers to the head section).
 * @return array associative, use raw and http_code to access the data.
 */
function ossn_embed_get_content($url, $mode = 'all') {
    $options = ['all' => [CURLOPT_HEADER => false, CURLOPT_FOLLOWLOCATION => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_AUTOREFERER => true],
        'header_only' => [CURLOPT_HEADER => true, CURLOPT_NOBODY => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_AUTOREFERER => true],
        'head_only' => [CURLOPT_HEADER => false, CURLOPT_FOLLOWLOCATION => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_AUTOREFERER => true]];
    $ch = curl_init($url);
    curl_setopt_array($ch, $options[$mode]);
    $raw = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($mode == 'head_only') {
        $raw = substr($raw, 0, stripos($raw, '</head>'));
    }
    return ['raw' => $raw, 'http_code' => $http_code];
}

/**
 * Tries to identify the root domain of a given url. Checks the header first for 
 * Location: , then for .domain= entries. Domain entries are enhanced with www 
 * using the http scheme of the url (e.g. http or https). In this case we trust
 * on redirects of the url owner in case www is not used. There are many entries
 * possible as a result of redirects, therefore the last entry is always preferred 
 * to resolve to the real root domain. If nothing matches, the root domain is extracted
 * from the given url.
 * Future versions should implement a caching mechanism to speed up processing, e.g.
 * like WordPress get/setTransient.
 * @param string $url to analyze. 
 * @return boolean|string false if curl fails or url is not set, otherwise the qualified url
 * for the root domain.
 */
function ossn_embed_get_root_domain($url) {
    // future versions should store data about domains we already have analyzed
    // to speed up processing, which needs a refresh function, in case domains have changed.
    if (!isset($url) || empty($url)) {
        return false;
    }
    $content = ossn_embed_get_content($url, 'header_only');
    $data = html_entity_decode($content['raw']);
    if (! $data) {
        return false;
    }
    $pattern_domain = '/domain=\.([^;.]*\.[^;.]{2,3})\;{0,1}/i';
    $pattern_location = '/location: (https?:\/\/)([^\/]*)/i';
    if (preg_match_all($pattern_location, $data, $locations)) {
        // get the last resolved location to get rid of redirects
        $root_position = count($locations[2]) - 1;
        return $locations[1][$root_position] . $locations[2][$root_position];
    } else if (preg_match_all($pattern_domain, $data, $domains)) {
        // enhance domain with www and trust on redirect
        $root_position = count($domains[1]) - 1;
        return parse_url($url, PHP_URL_SCHEME) . '://www.' . $domains[1][$root_position];
    } else {
        // could not identify root domain, extract it from $url
        return parse_url($url, PHP_URL_SCHEME) . '://' . parse_url($url, PHP_URL_HOST);
    }
}

function ossn_embed_build_code($ossn_meta) {
    $divEmbed = '<span id="ossnembed' . $ossn_meta['guid'] . '" class="ossn_embed_link embed-responsive embed-responsive-16by9">';
    $divEnd = '';
    if (empty($ossn_meta['embed'])) {
        if ($ossn_meta['type'] == $ossn_meta['map']['default'] && $ossn_meta['url'] != $ossn_meta['source']) {
            $divEmbed .= '<a href="' . $ossn_meta['url'] . '"> <img src="' . $ossn_meta['image'] . '">';
            $divEnd = '</a>';
        } else {
            $divEmbed .= '<iframe src="' . $ossn_meta['url'] . '" allowfullscreen>';
            $divEnd = '</iframe>';
        }
    } else {
        $divEmbed .= $ossn_meta['embed'];        
    }
    $divEmbed .= '<h3>' . $ossn_meta['title'] . '</h3>';
    $divEmbed .= substr($ossn_meta['desc'], 0, 135) . ' ...' . $divEnd;
    $divEmbed .= '</span>';
    $ossn_meta['embed'] = $divEmbed;
    return $ossn_meta;
}

/**
 * Tries to fetch meta data if ombed is not supported. First tries to get the
 * needed meta tags with get_meta_tags, if this fails, opens a curl session to
 * read the url data and analyzes them for open graph tags. If more than one
 * image is provided, the first found image is interpreted as site logo and 
 * the second one as content image. Further images are ignored at the moment.
 * This function may be slow, so future versions should use caching to speed 
 * up the process.
 * @param array $ossn_meta contains url and provides storage for analyzed data.
 * @return array filled with meta data ($ossn_meta). See ossn_embed_get_oembed
 * for array declaration.
 */
function ossn_embed_get_opengraph($ossn_meta) {
    if ($ossn_meta['head']['http_code'] < 400) {
        $meta = get_meta_tags($ossn_meta['url']);
    } else {
        // nevertheless we try to get some information but avoid errors on get_meta_tags.
        $meta = false;
    }
    // first check with fast get_meta_tags if we have twitter tag support
    if ($meta) {
        $ossn_meta['title'] = (!empty($meta['twitter:title'])) ? $meta['twitter:title'] : (empty($meta['title']) ? $ossn_meta['title'] : $meta['title']);
        $ossn_meta['desc'] = (!empty($meta['twitter:description'])) ? substr($meta['twitter:description'], 0, 140) : (empty($meta['description']) ? $ossn_meta['desc'] : substr($meta['description'], 0, 140));
        $ossn_meta['image'] = (!empty($meta['twitter:image'])) ? $meta['twitter:image'] : $ossn_meta['image'];
        $ossn_meta['logo'] = $ossn_meta['image'];
        $ossn_meta['site'] = (!empty($meta['twitter:site'])) ? $meta['twitter:site'] : $ossn_meta['site'];
        $ossn_meta['type'] = (!empty($meta['twitter:card'])) ? (array_key_exists($meta['twitter:card'], $ossn_meta['map']) ? $ossn_meta['map'][$meta['twitter:card']] : $ossn_meta['map']['default']): $ossn_meta['type'];
        $ossn_meta['width'] = (!empty($meta['twitter:player:width'])) ? $meta['twitter:player:width'] : $ossn_meta['width'];
        $ossn_meta['height'] = (!empty($meta['twitter:player:height'])) ? $meta['twitter:player:height'] : '';
        if ($ossn_meta['title'] && $ossn_meta['desc'] && $ossn_meta['image'] && $ossn_meta['type'] && $ossn_meta['width']) {
            return $ossn_meta;
        }
    }
    $html = html_entity_decode($ossn_meta['head']['raw']);
    // process data
    preg_match_all('/<[\s]*meta[\s]*(name|property)="?' . '(og\:[^>"]*)"?[\s]*' . 'content="?([^>"]*)"?[\s]*[\/]?[\s]*>/si', $html, $match);
    $meta_count = count($match['3']);
    if ($meta_count > 0) {
        for ($i=0; $i < $meta_count; $i++) {
            $tag_content = htmlentities(strip_tags($match[3][$i]), ENT_QUOTES);
            switch ($match[2][$i]) {
                case 'og:title':
                    $ossn_meta['title'] = empty($ossn_meta['title']) ? $tag_content : $ossn_meta['title'];
                    break;
                case 'og:description':
                    $ossn_meta['desc'] = empty($ossn_meta['desc']) ? $tag_content : $ossn_meta['desc'];
                    break;
                case 'og:type':
                    $ossn_meta['type'] = key_exists($tag_content, $ossn_meta['map']) ? $ossn_meta['map'][$tag_content] : $ossn_meta['type'];
                    break;
                case 'og:site_name':
                    $ossn_meta['site'] = empty($ossn_meta['site']) ? $tag_content : $ossn_meta['site'];
                    break;
                case 'og:image:width':
                case 'og:video:width':
                    $ossn_meta['width'] = empty($ossn_meta['width']) ? $tag_content : $ossn_meta['width'];
                    break;
                case 'og:image:height':
                case 'og:video:height':
                    $ossn_meta['height'] = empty($ossn_meta['height']) ? $tag_content : $ossn_meta['height'];
                    break;
                case 'og:image':
                    // get only the first two images, suppose the first is a logo
                    if (empty($ossn_meta['logo'])) {
                        $ossn_meta['logo'] = $match[3][$i];
                        $ossn_meta['image'] = $ossn_meta['logo'];
                    } else {
                        $ossn_meta['image'] = $ossn_meta['image'] == $ossn_meta['logo'] ? $match[3][$i] : $ossn_meta['image'];
                    }
                    break;
            }
        }
        if (empty($ossn_meta['title'])) {
            preg_match("/\<title\>(.*)\<\/title\>/i", $html, $match_title);
            if (!empty($match_title[1])) {
                $ossn_meta['title'] = trim(preg_replace('/\s+/', ' ', htmlentities(strip_tags($match_title[1]), ENT_QUOTES)));
            }
        }
        if (empty($ossn_meta['desc'])) {
            preg_match("/\<description\>(.*)\<\/description\>/i", $html, $match_desc);
            if (!empty($match_desc[1])) {
                $ossn_meta['desc'] = trim(preg_replace('/\s+/', ' ', htmlentities(strip_tags($match_desc[1]), ENT_QUOTES)));
            }
        }
    }
    return $ossn_meta;
}
/**
 * calculate the embed width and size
 *
 * @param array $ossn_meta contains the data for calculation.
 * @param $aspect_ratio
 * @param $toolbar_height
 */
function ossn_embed_calc_size($ossn_meta, $aspect_ratio = 425/320, $toolbar_height = 24) {
    
    // make sure width is a number and greater than zero
    if (!isset($ossn_meta['width']) || !is_numeric($ossn_meta['width']) || $ossn_meta['width'] < 0) {
        $ossn_meta['width'] = 500;
    }
    $ossn_meta['height'] = round($ossn_meta['width'] / $aspect_ratio) + $toolbar_height;
    return $ossn_meta;
}

/**
 * generic css insert
 *
 * @param array $ossn_meta which contains all needed data.
 * @return string style code for embed div
 */
function ossn_embed_add_css($ossn_meta) {
    // compatibility hack to work with ReadMore component
    // first, close still open post-text <div> here, otherwise video will become a part of collapsible area
    $embedtype = $ossn_meta['type'] . ':css';
    $embedcss = "";
    $vars = array(
        'guid' => $ossn_meta['guid'],
        'width' => $ossn_meta['width'],
        'height' => $ossn_meta['height']
    );
    return ossn_call_hook('embed', $embedtype, $vars, $embedcss);
}

/**
 * Builds a div for a given url that displays videos, pictures, titles and
 * description if available. Use oembed if available or open graph tags.
 * @param string $url to embed.
 * @param integer $guid unique identifier of the widget.
 * @param integer $objectwidth preferred.
 * @return string a span div for embedding the url information.
 */
function ossn_embed_get_oembed($url, $guid, $objectwidth) {
    $supported_types = ['photo'               => 'link',
                        'video'               => 'video',
                        'link'                => 'link',
                        'rich'                => 'link',
                        'music.song'          => 'link',
                        'music.album'         => 'link',
                        'music.playlist'      => 'link',
                        'music.radio_station' => 'link',
                        'video.movie'         => 'video',
                        'video.episode'       => 'video',
                        'video.tv_show'       => 'video',
                        'video.other'         => 'video',
                        'article'             => 'link',
                        'book'                => 'link',
                        'profile'             => 'link',
                        'website'             => 'link',
                        'default'             => 'link'];
    $ossn_meta = ['url'    => $url,
                  'source' => ossn_embed_get_root_domain($url),
                  'head'   => ossn_embed_get_content($url, 'head_only'),  
                  'title'  => false,
                  'desc'   => false,
                  'logo'   => false,
                  'image'  => false,
                  'type'   => $supported_types['default'],
                  'site'   => false,
                  'guid'   => $guid,
                  'embed'  => false, 
                  'width'  =>  $objectwidth,
                  'height' => 0,
                  'map'    => $supported_types];
    $embed_info = [];
    if (preg_match( '/<link.*json\+oembed".*href="([^<> ]+)".*>/i', $ossn_meta['head']['raw'], $oembed_link ) ) {
        // if the site provides a json oembed link, we catch the first one
        $embed_info = json_decode(ossn_embed_get_content($oembed_link[1])['raw'], true);
    } else {
        // okay we just try a standard url
        $embed_info = json_decode(ossn_embed_get_content($ossn_meta['source'] . '/oembed?format=json&url=' . $url)['raw'], true);
    }
    $embed_info = ($embed_info == NULL) ? [] : $embed_info;
    if (!empty($embed_info)) {
        $ossn_meta['title']  = empty($embed_info['title']) ? $ossn_meta['title'] : $embed_info['title'];
        $ossn_meta['desc']   = empty($embed_info['desc']) ? (empty($embed_info['description']) ? $ossn_meta['desc'] : $embed_info['description']) : $embed_info['desc'];
        $ossn_meta['image']  = empty($embed_info['image']) ? (empty($embed_info['thumbnail_url']) ? $ossn_meta['image']: $embed_info['thumbnail_url']) : $embed_info['image'];
        $ossn_meta['logo']   = empty($embed_info['logo']) ? (empty($ossn_meta['image']) ? $ossn_meta['logo'] : $ossn_meta['image']) : $embed_info['logo'];
        $ossn_meta['type']   = empty($embed_info['type']) ? $ossn_meta['type'] : $ossn_meta['map'][$embed_info['type']];
        $ossn_meta['site']   = empty($embed_info['site']) ? $ossn_meta['source'] : $embed_info['site'];
        $ossn_meta['embed']  = empty($embed_info['html']) ? $ossn_meta['embed'] : $embed_info['html'];
        $ossn_meta['width']  = empty($embed_info['width']) ? $ossn_meta['width'] : $embed_info['width'];
        $ossn_meta['height'] = empty($embed_info['height']) ? $ossn_meta['height'] : $embed_info['height'];
        // clean out wordpress embed as long as it is not working well
        if (strpos($ossn_meta['embed'], 'wp-embedded-content') > 0) $ossn_meta['embed'] = '';
    } 
    if ($embed_info == NULL || empty($ossn_meta['embed'])) {
        // fetch more info, we only exit with a valid embed code
        $ossn_meta = ossn_embed_get_opengraph($ossn_meta);        
    }
    $ossn_meta['title'] = str_replace(PHP_EOL, '', $ossn_meta['title']);
    $ossn_meta['desc'] = substr(str_replace(PHP_EOL, '', $ossn_meta['desc']), 0, 140);
    $ossn_meta = ossn_embed_calc_size($ossn_meta);
    $ossn_meta = ossn_embed_build_code($ossn_meta);
    $embed_object = ossn_embed_add_css($ossn_meta);
    $embed_object .= $ossn_meta['embed'];
    return $embed_object;
}
