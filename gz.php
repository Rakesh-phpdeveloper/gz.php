<?php
/**
 * gz.php - GZIP compression with mod_rewrite and PHP
 * 
 * --
 * Copyright (c) 2012 Florian Höch <florian.hoech@gmx.de>
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 * 
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 * 
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 * --
 * 
 * @package		gz.php
 * @link		https://github.com/fhoech/gz.php
 * @author		Florian Höch <florian.hoech@gmx.de>
 * @copyright	2012 Florian Höch <florian.hoech@gmx.de>
 * @license		http://opensource.org/licenses/mit-license.php MIT License
 * @version		1.0
 */

function get_content_type($file) {
    // Determine Content-Type based on file extension
    $info = pathinfo($file);
    $content_types = array('css' => 'text/css; charset=UTF-8',
                           'htm' => 'text/html; charset=UTF-8',
                           'html' => 'text/html; charset=UTF-8',
                           'gif' => 'image/gif',
                           'ico' => 'image/x-icon',
                           'jpg' => 'image/jpeg',
                           'jpeg' => 'image/jpeg',
                           'js' => 'application/javascript',
                           'json' => 'application/json',
                           'png' => 'image/png',
                           'svg' => 'image/svg+xml',
                           'txt' => 'text/plain',
                           'xml' => 'application/xml');
    if (empty($content_types[$info['extension']]))
        return NULL;
    return $content_types[$info['extension']];
}

function main() {
    // Get file path by stripping query parameters from the request URI
    if (!empty($_SERVER['REQUEST_URI']))
        $path = preg_replace('/\/?(?:\?.*)?$/', '', $_SERVER['REQUEST_URI']);

    // If the path is empty, either use DEFAULT_FILENAME if defined, or exit
    if (empty($path)) {
        if (defined('DEFAULT_FILENAME')) $path = '/' . DEFAULT_FILENAME;
        else die();
    }

    $file = dirname(__FILE__) . $path;
    if (!file_exists($file)) die();
    
    // Determine Content-Type based on file extension
    $content_type = get_content_type($file);
    if ($content_type == NULL) die();

    $mtime = filemtime($file);

    // If the user agent sent a IF_MODIFIED_SINCE header, check if the file
    // has been modified. If it hasn't, send '304 Not Modified' header & exit
    if (!empty($_SERVER['HTTP_IF_MODIFIED_SINCE']) &&
        $mtime <= strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE'])) {
        header($_SERVER['SERVER_PROTOCOL'] . ' 304 Not Modified', true, 304);
        exit;
    }

    // If the user agent accepts GZIP encoding, store a compressed version of
    // the file (<filename>.gz)
    if (strpos($file, '.php.') === false && /* Fix for 1&1 config */
        !empty($_SERVER['HTTP_ACCEPT_ENCODING']) &&
        in_array('gzip', preg_split('/\s*,\s*/',
                                    $_SERVER['HTTP_ACCEPT_ENCODING']))) {
        // Only write the compressed version if it does not yet exist or the
        // original file has changed
        $gzfile = $file . '.gz';
        if (!file_exists($gzfile) || filemtime($gzfile) < $mtime) {
			$buffer = file_get_contents($file);
            if (preg_match_all('/<!--#include file="([^"]+)" -->/',
                               $buffer, $matches, PREG_SET_ORDER)) {
                // Includes
                $path = dirname($file);
                foreach ($matches as $set) {
                    $include = $set[1];
                    if (realpath($set[1]) != $set[1])
                        $include = $path . '/' . $set[1];
                    if (file_exists($include))
                        $buffer = str_replace($set[0],
                                              file_get_contents($include),
                                              $buffer);
                }
            }
			// Minify CSS and JS if the filename does not contain 'min.<ext>'
			switch ($content_type) {
				case 'text/css; charset=UTF-8':
					if (strpos($file, 'min.css') === false) {
						require_once('cssmin-v3.0.1.php');
						$buffer = CssMin::minify($buffer);
					}
					break;
				case 'application/javascript':
					if (strpos($file, 'min.js') === false) {
						require_once('jsmin.php');
						$buffer = JSMin::minify($buffer);
					}
					break;
			}
            file_put_contents($gzfile, gzencode($buffer));
        }
        // Send compression headers and use the .gz file instead of the
        // original filename
        header('Content-Encoding: gzip');
        $file = $file . '.gz';
    }

    // Vary max-age and expiration headers based on content type
    switch ($content_type) {
        case 'image/gif':
        case 'image/jpeg':
        case 'image/png':
        case 'image/svg+xml':
            // Max-age for images: 31 days
            $maxage = 60 * 60 * 24 * 31;
            break;
        default:
            // Max-age for everything else: 7 days
            $maxage = 60 * 60 * 24 * 7;
    }

    // Send remaining headers
    header('Vary: Accept-Encoding');
    header('Cache-Control: max-age=' . $maxage);
    header('Expires: ' . gmdate('D, d M Y H:i:s', time() + $maxage) . ' GMT');
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
    header('Content-Type: ' . $content_type);
    header('Content-Length: ' . filesize($file));
    
    // If the request method isn't HEAD, send the file contents
    if ($_SERVER['REQUEST_METHOD'] != 'HEAD') readfile($file);
}

main();

?>