<?php
/**
 * Open Source Social Network
 *
 * @package Open Source Social Network
 * @author    Open Social Website Core Team <info@softlab24.com>
 * @copyright 2014-2017 SOFTLAB24 LIMITED
 * @license   Open Source Social Network License (OSSN LICENSE)  http://www.opensource-socialnetwork.org/licence
 * @link      https://www.opensource-socialnetwork.org/
 */
 
/**
 * Library modifed for Ossn, Original code is from Cash Costello.
 * Embed Video Library
 * Functions to parse flash video urls and create the flash embed object
 *
 * @package Embed Video Library
 * @license http://www.gnu.org/licenses/gpl.html GNU Public License version 2
 * @author Cash Costello
 * @copyright Cash Costello 2009-2011
 *
 *
 * Current video sites supported:
 *
 * youtube/youtu.be
 * vimeo
 * metacafe
 * veoh
 * dailymotion
 * blip.tv
 * teacher tube
 * hulu
 *
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

/**
 * Scans the embed code for sandbox attribute, if not included, adds the sandbox
 * attribute otherwise strips every parameter from the sandbox attribute (full restriction).
 * @param String $embed_code as returned by oembed.
 * @return string the oembed code with strict sandbox attribute.
 */
function ossn_embed_sandbox_it($embed_code) {
    $pattern_frame = '/(.*)(<iframe?[^>])(.*)(><\/iframe>)(.*)/i';
    preg_match($pattern_frame, $embed_code, $match_frame);
    if (strpos(strtolower($match_frame[3]), 'sandbox') > 0) {
        $pattern_sandbox = '/(.*)(sandbox)([=]?["]?[a-zA-Z ].+["]?)(.*)/i';
        preg_match($pattern_sandbox, $match_frame[3], $match_sandbox);
        $match_sandbox[0] = '';
        $match_sandbox[3] = '';
        $match_frame[3] = implode('', $match_sandbox);
    } else {
        $match_frame[3] .= ' sandbox';
    }
    $match_frame[0] = '';
    $embed_code = implode('', $match_frame);
    return $embed_code;
}

function ossn_embed_build_code($ossn_meta) {
    $divEmbed = '<span id="ossnembed' . $ossn_meta['guid'] . '" class="ossn_embed_link embed-responsive ' . ($ossn_meta['image'] == false ? '">' : 'embed-responsive-16by9">');
    if ($ossn_meta['embed'] == false) {
        if ($ossn_meta['type'] == $ossn_meta['map']['default']) {
            $divEmbed .= '<a href="' . $ossn_meta['url'] . '"> <img src="' . $ossn_meta['image'] . '"></a>';
            $divEmbed .= '<h3>' . $ossn_meta['title'] . '</h3>';
            $divEmbed .= substr($ossn_meta['desc'], 0, 135) . ' ...' . $divEnd;
        } else {
            $divEmbed .= '<iframe src="' . $ossn_meta['url'] . '" allowfullscreen sandbox></iframe>';
        }
    } else {
        $divEmbed .= $ossn_meta['embed'];        
    }
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
        if ($ossn_meta['title'] !== false  && $ossn_meta['desc'] !== false && $ossn_meta['image'] !== false) {
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
                    $ossn_meta['title'] = $ossn_meta['title'] == false ? $tag_content : $ossn_meta['title'];
                    break;
                case 'og:description':
                    $ossn_meta['desc'] = $ossn_meta['desc'] == false ? $tag_content : $ossn_meta['desc'];
                    break;
                case 'og:type':
                    $ossn_meta['type'] = key_exists($tag_content, $ossn_meta['map']) ? $ossn_meta['map'][$tag_content] : $ossn_meta['type'];
                    break;
                case 'og:site_name':
                    $ossn_meta['site'] = $ossn_meta['site'] == false ? $tag_content : $ossn_meta['site'];
                    break;
                case 'og:image:width':
                case 'og:video:width':
                    $ossn_meta['width'] = $ossn_meta['width'] == 0 ? $tag_content : $ossn_meta['width'];
                    break;
                case 'og:image:height':
                case 'og:video:height':
                    $ossn_meta['height'] = $ossn_meta['height'] == 0 ? $tag_content : $ossn_meta['height'];
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
        if ($ossn_meta['title'] == false) {
            preg_match("/\<title\>(.*)\<\/title\>/i", $html, $match_title);
            if (!empty($match_title[1])) {
                $ossn_meta['title'] = trim(preg_replace('/\s+/', ' ', htmlentities(strip_tags($match_title[1]), ENT_QUOTES)));
            }
        }
        if ($ossn_meta['desc'] == false) {
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
        if (strpos($ossn_meta['embed'], 'wp-embedded-content') > 0) $ossn_meta['embed'] = false;
    } 
    if ($embed_info == NULL || $ossn_meta['embed'] == false) {
        // fetch more info, we only exit with a valid embed code
        $ossn_meta = ossn_embed_get_opengraph($ossn_meta);        
    }
    if ($ossn_meta['title'] != false) $ossn_meta['title'] = str_replace(PHP_EOL, '', $ossn_meta['title']);
    if ($ossn_meta['desc'] != false) $ossn_meta['desc'] = substr(str_replace(PHP_EOL, '', $ossn_meta['desc']), 0, 140);
    $ossn_meta = ossn_embed_calc_size($ossn_meta);
    $ossn_meta = ossn_embed_build_code($ossn_meta);
    $embed_object = ossn_embed_add_css($ossn_meta);
    $embed_object .= $ossn_meta['embed'];
    return $embed_object;
}

/**
 * generic <object> creator
 *
 * @param string $type
 * @param string $url
 * @param integer $guid unique identifier of the widget
 * @param integer/string $width
 * @param integer/string $height
 * @return string <object> code
 */
function ossn_embed_add_object($type, $url, $guid, $width, $height) {
    $embeddiv = '';
	$videodiv = "<span id=\"ossnembed{$guid}\" class=\"ossn_embed_video embed-responsive embed-responsive-16by9\">";
	$linkdiv = "<span id=\"ossnembed{$guid}\" class=\"ossn_embed_link embed-responsive embed-responsive-16by9\">";
	
	// could move these into an array and use sprintf
	switch ($type) {
		case 'youtube':
			//youtube https in ossnembed.lib.php #519
			$videodiv .= "<iframe src=\"https://{$url}\" allowfullscreen></iframe>";
			break;
		case 'google':
			$videodiv .= "<embed class='embed-responsive-item' id=\"VideoPlayback\" src=\"http://video.google.com/googleplayer.swf?docid={$url}&hl=en&fs=true\" style=\"width:{$width}px;height:{$height}px\" allowFullScreen=\"true\" allowScriptAccess=\"always\" type=\"application/x-shockwave-flash\"> </embed>";
			break;
		case 'vimeo':
			$videodiv .= "<iframe src=\"https://player.vimeo.com/video/{$url}\" allowfullscreen></iframe>";
			break;
		case 'metacafe':
			$videodiv .= "<embed class='embed-responsive-item' src=\"http://www.metacafe.com/fplayer/{$url}.swf\" width=\"$width\" height=\"$height\" wmode=\"transparent\" pluginspage=\"http://www.macromedia.com/go/getflashplayer\" type=\"application/x-shockwave-flash\"></embed>";
			break;
		case 'veoh':
			$videodiv .= "<embed class='embed-responsive-item' src=\"http://www.veoh.com/veohplayer.swf?permalinkId={$url}&player=videodetailsembedded&videoAutoPlay=0\" allowFullScreen=\"true\" width=\"$width\" height=\"$height\" bgcolor=\"#FFFFFF\" type=\"application/x-shockwave-flash\" pluginspage=\"http://www.macromedia.com/go/getflashplayer\"></embed>";
			break;
		case 'dm':
			$videodiv .= "<iframe src=\"//www.dailymotion.com/embed/video/{$url}\" width=\"$width\" height=\"$height\" allowFullScreen></iframe>"; 
			break;
		case 'teacher':
			$videodiv .= "<embed class='embed-responsive-item' src=\"http://www.teachertube.com/embed/player.swf\" width=\"$width\" height=\"$height\" type=\"application/x-shockwave-flash\" allowscriptaccess=\"always\" allowfullscreen=\"true\" flashvars=\"file=http://www.teachertube.com/embedFLV.php?pg=video_{$url}&menu=false&&frontcolor=ffffff&lightcolor=FF0000&logo=http://www.teachertube.com/www3/images/greylogo.swf&skin=http://www.teachertube.com/embed/overlay.swf&volume=80&controlbar=over&displayclick=link&viral.link=http://www.teachertube.com/viewVideo.php?video_id={$url}&stretching=exactfit&plugins=viral-2&viral.callout=none&viral.onpause=false\"></embed>";
			break;
		case 'hulu':
			$videodiv .= "<object class='embed-responsive-item' width=\"{$width}\" height=\"{$height}\"><param name=\"movie\" value=\"http://www.hulu.com/embed/{$url}\"></param><param name=\"allowFullScreen\" value=\"true\"></param><embed src=\"http://www.hulu.com/embed/{$url}\" type=\"application/x-shockwave-flash\" allowFullScreen=\"true\"  width=\"{$width}\" height=\"{$height}\"></embed></object>";
			break;
	}

	$videodiv .= "</span>";
	// re-open post-text again (last closing </div> comes with wall code as before )
	// hmm no need for div post-text without ending tag , removed it from here and removed ending tag from ossn_embed_add_css() 
	// $arsalanshah 12/4/2015
	return $videodiv;
}

/**
 * main youtube interface
 *
 * @param string $url
 * @param integer $guid unique identifier of the widget
 * @param integer $videowidth  optional override of admin set width
 * @return string css style, video div, and flash <object>
 */
function ossn_embed_youtube_handler($url, $guid, $videowidth) {
	// this extracts the core part of the url needed for embeding
	$videourl = ossn_embed_youtube_parse_url($url);
	if (!isset($videourl)) {
		return false;
	}

	ossn_embed_calc_size($videowidth, $videoheight, 425/320, 24);

	$embed_object = ossn_embed_add_css($guid, $videowidth, $videoheight);

	$embed_object .= ossn_embed_add_object('youtube', $videourl, $guid, $videowidth, $videoheight);

	return $embed_object;
}

/**
 * parse youtube url
 *
 * @param string $url
 * @return string subdomain.youtube.com/v/hash
 */
function ossn_embed_youtube_parse_url($url) {

	if (strpos($url, 'feature=hd') != false) {
		// this is high def with a different aspect ratio
	}

	// This provides some security against inserting bad content.
	// Divides url into http://, www or localization, domain name, path.
	if (!preg_match('/(https?:\/\/)([a-zA-Z]{2,3}\.)(youtube\.com\/)(.*)/', $url, $matches)) {
		//echo "malformed youtube url";
		return;
	}

	$domain = $matches[2] . $matches[3];
	$path = $matches[4];

	$parts = parse_url($url);
	parse_str($parts['query'], $vars);
	$hash = $vars['v'];

	return $domain . 'embed/' . $hash;
}

/**
 * parse youtu.be url
 *
 * @param string $url
 * @return string youtube.com/v/hash
 */
function ossn_embed_youtube_shortener_parse_url($url) {
	$path = parse_url($url, PHP_URL_PATH);
	$videourl = 'youtube.com/embed' . $path;

	ossn_embed_calc_size($videowidth, $videoheight, 425/320, 24);

	$embed_object = ossn_embed_add_css($guid, $videowidth, $videoheight);

	$embed_object .= ossn_embed_add_object('youtube', $videourl, $guid, $videowidth, $videoheight);

	return $embed_object;
}

/**
 * main google interface
 *
 * @param string $url
 * @param integer $guid unique identifier of the widget
 * @param integer $videowidth  optional override of admin set width
 * @return string css style, video div, and flash <object>
 */
function ossn_embed_google_handler($url, $guid, $videowidth) {
	// this extracts the core part of the url needed for embeding
	$videourl = ossn_embed_google_parse_url($url);
	if (!isset($videourl)) {
		return false;
	}

	ossn_embed_calc_size($videowidth, $videoheight, 400/300, 27);

	$embed_object = ossn_embed_add_css($guid, $videowidth, $videoheight);

	$embed_object .= ossn_embed_add_object('google', $videourl, $guid, $videowidth, $videoheight);

	return $embed_object;
}

/**
 * parse google url
 *
 * @param string $url
 * @return string hash
 */
function ossn_embed_google_parse_url($url) {
	// separate parsing embed url
	if (strpos($url, 'embed') != false) {
		return ossn_embed_google_parse_embed($url);
	}

	if (!preg_match('/(https?:\/\/)(video\.google\.com\/videoplay)(.*)/', $url, $matches)) {
		//echo "malformed google url";
		return;
	}

	$path = $matches[3];
	//echo $path;

	// forces rest of url to start with "?docid=", followed by hash, and rest of options start with &
	if (!preg_match('/^(\?docid=)([0-9-]*)#?(&.*)?$/',$path, $matches)) {
		//echo "bad hash";
		return;
	}

	$hash = $matches[2];
	//echo $hash;

	return $hash;
}

/**
 * parse google embed code
 *
 * @param string $url
 * @return string hash
 */
function ossn_embed_google_parse_embed($url) {

	if (!preg_match('/(src=)(https?:\/\/video\.google\.com\/googleplayer\.swf\?docid=)([0-9-]*)(&hl=[a-zA-Z]{2})(.*)/', $url, $matches)) {
		//echo "malformed embed google url";
		return;
	}

	$hash   = $matches[3];
	//echo $hash;

	// need to pull out language here
	//echo $matches[4];

	return $hash;
}

/**
 * main vimeo interface
 *
 * @param string $url
 * @param integer $guid unique identifier of the widget
 * @param integer $videowidth  optional override of admin set width
 * @return string css style, video div, and flash <object>
 */
function ossn_embed_vimeo_handler($url, $guid, $videowidth) {
	// this extracts the core part of the url needed for embeding
	$videourl = ossn_embed_vimeo_parse_url($url);
	if (!isset($videourl)) {
		return false;
	}

	// aspect ratio changes based on video - need to investigate
	ossn_embed_calc_size($videowidth, $videoheight, 400/300, 0);

	$embed_object = ossn_embed_add_css($guid, $videowidth, $videoheight);

	$embed_object .= ossn_embed_add_object('vimeo', $videourl, $guid, $videowidth, $videoheight);

	return $embed_object;
}

/**
 * parse vimeo url
 *
 * @param string $url
 * @return string hash
 */
function ossn_embed_vimeo_parse_url($url) {
	// separate parsing embed url
	if (strpos($url, 'object') != false) {
		return ossn_embed_vimeo_parse_embed($url);
	}

	if (strpos($url, 'groups') != false) {
		if (!preg_match('/(https?:\/\/)(www\.)?(vimeo\.com\/groups)(.*)(\/videos\/)([0-9]*)/', $url, $matches)) {
			//echo "malformed vimeo group url";
			return;
		}
		return $matches[6];
	} 
	
	if (preg_match('/(https:\/\/)(www\.)?(vimeo.com\/)([0-9]*)/', $url, $matches)) {
			// this is the "share" link suggested by vimeo 
			return $matches[4];
	}
		
	if (preg_match('/(https:\/\/)(player\.)?(vimeo.com\/video\/)([0-9]*)/', $url, $matches)) {
			// that's the "embed" link suggested by vimeo
			return $matches[4];
	}

}

/**
 * parse vimeo embed code
 *
 * @param string $url
 * @return string hash
 */
function ossn_embed_vimeo_parse_embed($url) {
	if (!preg_match('/(value="https?:\/\/vimeo\.com\/moogaloop\.swf\?clip_id=)([0-9-]*)(&)(.*" \/)/', $url, $matches)) {
		//echo "malformed embed vimeo url";
		return;
	}

	$hash   = $matches[2];
	//echo $hash;

	return $hash;
}

/**
 * main metacafe interface
 *
 * @param string $url
 * @param integer $guid unique identifier of the widget
 * @param integer $videowidth  optional override of admin set width
 * @return string css style, video div, and flash <object>
 */
function ossn_embed_metacafe_handler($url, $guid, $videowidth) {
	// this extracts the core part of the url needed for embeding
	$videourl = ossn_embed_metacafe_parse_url($url);
	if (!isset($videourl)) {
		return false;
	}

	ossn_embed_calc_size($videowidth, $videoheight, 400/295, 40);

	$embed_object = ossn_embed_add_css($guid, $videowidth, $videoheight);

	$embed_object .= ossn_embed_add_object('metacafe', $videourl, $guid, $videowidth, $videoheight);

	return $embed_object;
}

/**
 * parse metacafe url
 *
 * @param string $url
 * @return string hash
 */
function ossn_embed_metacafe_parse_url($url) {
	// separate parsing embed url
	if (strpos($url, 'embed') != false) {
		return ossn_embed_metacafe_parse_embed($url);
	}

	if (!preg_match('/(https?:\/\/)(www\.)?(metacafe\.com\/watch\/)([0-9a-zA-Z_-]*)(\/[0-9a-zA-Z_-]*)(\/)/', $url, $matches)) {
		//echo "malformed metacafe group url";
		return;
	}

	$hash = $matches[4] . $matches[5];

	//echo $hash;

	return $hash;
}

/**
 * parse metacafe embed code
 *
 * @param string $url
 * @return string hash
 */
function ossn_embed_metacafe_parse_embed($url) {
	if (!preg_match('/(src="https?:\/\/)(www\.)?(metacafe\.com\/fplayer\/)([0-9]*)(\/[0-9a-zA-Z_-]*)(\.swf)/', $url, $matches)) {
		//echo "malformed embed metacafe url";
		return;
	}

	$hash   = $matches[4] . $matches[5];
	//echo $hash;

	return $hash;
}

/**
 * main veoh interface
 *
 * @param string $url
 * @param integer $guid unique identifier of the widget
 * @param integer $videowidth  optional override of admin set width
 * @return string css style, video div, and flash <object>
 */
function ossn_embed_veoh_handler($url, $guid, $videowidth) {
	// this extracts the core part of the url needed for embeding
	$videourl = ossn_embed_veoh_parse_url($url);
	if (!isset($videourl)) {
		return false;
	}

	ossn_embed_calc_size($videowidth, $videoheight, 410/311, 30);

	$embed_object = ossn_embed_add_css($guid, $videowidth, $videoheight);

	$embed_object .= ossn_embed_add_object('veoh', $videourl, $guid, $videowidth, $videoheight);

	return $embed_object;
}

/**
 * parse veoh url
 *
 * @param string $url
 * @return string hash
 */
function ossn_embed_veoh_parse_url($url) {
	// separate parsing embed url
	if (strpos($url, 'embed') != false) {
		return ossn_embed_veoh_parse_embed($url);
	}

	if (!preg_match('/(http:\/\/www\.veoh\.com\/.*\/videos#watch%3D)([0-9a-zA-Z]*)/', $url, $matches)) {
		//echo "malformed veoh url";
		return;
	}

	$hash = $matches[2];

	//echo $hash;

	return $hash;
}

/**
 * parse veoh embed code
 *
 * @param string $url
 * @return string hash
 */
function ossn_embed_veoh_parse_embed($url) {
	if (!preg_match('/(src="https?:\/\/)(www\.)?(veoh\.com\/static\/swf\/webplayer\/WebPlayer\.swf\?version=)([0-9a-zA-Z.]*)&permalinkId=([a-zA-Z0-9]*)&(.*)/', $url, $matches)) {
		//echo "malformed embed veoh url";
		return;
	}

	$hash   = $matches[5];
	//echo $hash;

	return $hash;
} 

/**
 * main dm interface
 *
 * @param string $url
 * @param integer $guid unique identifier of the widget
 * @param integer $videowidth  optional override of admin set width
 * @return string css style, video div, and flash <object>
 */
function ossn_embed_dm_handler($url, $guid, $videowidth) {
	// this extracts the core part of the url needed for embeding
	$videourl = ossn_embed_dm_parse_url($url);
	if (!isset($videourl)) {
		return false;
	}

	ossn_embed_calc_size($videowidth, $videoheight, 420/300, 35);

	$embed_object = ossn_embed_add_css($guid, $videowidth, $videoheight);

	$embed_object .= ossn_embed_add_object('dm', $videourl, $guid, $videowidth, $videoheight);

	return $embed_object;
}

/**
 * parse dm url
 *
 * @param string $url
 * @return string hash
 */
function ossn_embed_dm_parse_url($url) {
	// separate parsing embed url
	if (strpos($url, 'embed') != false) {
		return ossn_embed_dm_parse_embed($url);
	}

	if (!preg_match('/(http:\/\/www\.dailymotion\.com\/.*\/)([0-9a-z]*)/', $url, $matches)) {
		//echo "malformed daily motion url";
		return;
	}

	$hash = $matches[2];

	//echo $hash;

	return $hash;
}

/**
 * parse dm embed code
 *
 * @param string $url
 * @return string hash
 */
function ossn_embed_dm_parse_embed($url) {
	if (!preg_match('/(value="http:\/\/)(www\.)?dailymotion\.com\/swf\/video\/([a-zA-Z0-9]*)/', $url, $matches)) {
		//echo "malformed embed daily motion url";
		return;
	}

	$hash   = $matches[3];
	//echo $hash;

	return $hash;
} 

/**
 * main blip interface
 *
 * @param string $url
 * @param integer $guid unique identifier of the widget
 * @param integer $videowidth  optional override of admin set width
 * @return string css style, video div, and flash <object>
 */
function ossn_embed_blip_handler($url, $guid, $videowidth) {
	// this extracts the core part of the url needed for embeding
	$videourl = ossn_embed_blip_parse_url($url);
	if (!is_array($videourl)) {
		return false;
	}

	$width = $videourl[1];
	$height = $videourl[2] - 30;

	ossn_embed_calc_size($videowidth, $videoheight, $width/$height, 30);

	$embed_object = ossn_embed_add_css($guid, $videowidth, $videoheight);

	$embed_object .= ossn_embed_add_object('blip', $videourl[0], $guid, $videowidth, $videoheight);

	return $embed_object;
}

/**
 * parse blip url
 *
 * @param string $url
 * @return string hash
 */
function ossn_embed_blip_parse_url($url) {
	// separate parsing embed url
	if (strpos($url, 'embed') === false) {
		return 1;
	}

	if (!preg_match('/(src="https?:\/\/blip\.tv\/play\/)([a-zA-Z0-9%]*)(.*width=")([0-9]*)(.*height=")([0-9]*)/', $url, $matches)) {
		//echo "malformed blip.tv url";
		return 2;
	}

	$hash[0] = $matches[2];
	$hash[1] = $matches[4];
	$hash[2] = $matches[6];

	//echo $hash[0];

	return $hash;
}

/**
 * main teacher tube interface
 *
 * @param string $url
 * @param integer $guid unique identifier of the widget
 * @param integer $videowidth  optional override of admin set width
 * @return string css style, video div, and flash <object>
 */
function ossn_embed_teachertube_handler($url, $guid, $videowidth) {
	// this extracts the core part of the url needed for embeding
	$videourl = ossn_embed_teachertube_parse_url($url);
	if (!is_numeric($videourl)) {
		return false;
	}

	ossn_embed_calc_size($videowidth, $videoheight, 425/330, 20);

	$embed_object = ossn_embed_add_css($guid, $videowidth, $videoheight);

	$embed_object .= ossn_embed_add_object('teacher', $videourl, $guid, $videowidth, $videoheight);

	return $embed_object;
}

/**
 * parse teachertube url
 *
 * @param string $url
 * @return string hash
 */
function ossn_embed_teachertube_parse_url($url) {
	// separate parsing embed url
	if (strpos($url, 'embed') !== false) {
		return ossn_embed_teachertube_parse_embed($url);;
	}

	if (!preg_match('/(https?:\/\/www\.teachertube\.com\/viewVideo\.php\?video_id=)([0-9]*)&(.*)/', $url, $matches)) {
		//echo "malformed teacher tube url";
		return;
	}

	$hash = $matches[2];

	echo $hash;

	return $hash;
}

/**
 * parse teacher tube embed code
 *
 * @param string $url
 * @return string hash
 */
function ossn_embed_teachertube_parse_embed($url) {
	if (!preg_match('/(flashvars="file=https?:\/\/www\.teachertube\.com\/embedFLV.php\?pg=video_)([0-9]*)&(.*)/', $url, $matches)) {
		//echo "malformed teacher tube embed code";
		return;
	}

	$hash   = $matches[2];
	//echo $hash;

	return $hash;
}

/**
 * main hulu interface
 *
 * @param string $url
 * @param integer $guid unique identifier of the widget
 * @param integer $videowidth  optional override of admin set width
 * @return string css style, video div, and flash <object>
 */
function ossn_embed_hulu_handler($url, $guid, $videowidth) {
	// this extracts the core part of the url needed for embeding
	$videourl = ossn_embed_hulu_parse_url($url);
	if (is_numeric($videourl)) {
		return false;
	}

	ossn_embed_calc_size($videowidth, $videoheight, 512/296, 0);

	$embed_object = ossn_embed_add_css($guid, $videowidth, $videoheight);

	$embed_object .= ossn_embed_add_object('hulu', $videourl, $guid, $videowidth, $videoheight);

	return $embed_object;
}

/**
 * parse hulu url
 *
 * @param string $url
 * @return string hash
 */
function ossn_embed_hulu_parse_url($url) {
	// separate parsing embed url
	if (strpos($url, 'embed') === false) {
		return 1;
	}

	if (!preg_match('/(value="https?:\/\/www\.hulu\.com\/embed\/)([a-zA-Z0-9_-]*)"(.*)/', $url, $matches)) {
		//echo "malformed blip.tv url";
		return 2;
	}

	$hash = $matches[2];

	//echo $hash;

	return $hash;
}

function ossn_embed_link($url, $guid, $videowidth) {
    ;
}