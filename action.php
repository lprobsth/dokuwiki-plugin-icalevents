<?php
/**
 * Plugin iCalEvents: Renders an iCalendar file, e.g., as a table.
 *
 * Copyright (C) 2010-2012, 2015-2016
 * Tim Ruffing, Robert Rackl, Elan Ruusamäe, Jannes Drost-Tenfelde
 *
 * This file is part of the DokuWiki iCalEvents plugin.
 *
 * The DokuWiki iCalEvents plugin program is free software:
 * you can redistribute it and/or modify it under the terms of the
 * GNU General Public License version 2 as published by the Free
 * Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * version 2 along with the DokuWiki iCalEvents plugin program.  If
 * not, see <http://www.gnu.org/licenses/gpl-2.0.html>.
 *
 * @license    https://www.gnu.org/licenses/gpl-2.0.html GPL2
 * @author     Tim Ruffing <tim@timruffing.de>
 * @author     Robert Rackl <wiki@doogie.de>
 * @author     Elan Ruusamäe <glen@delfi.ee>
 * @author     Jannes Drost-Tenfelde <info@drost-tenfelde.de>
 *
 */

use dokuwiki\Cache\Cache; 
use Sabre\VObject;

 /**
 * Action part: makes the show/hide strings available in the browser
 */
class action_plugin_icalevents extends DokuWiki_Action_Plugin {
    /**
     * Register the handle function in the controller
     *
     * @param Doku_event_handler $controller The event controller
     */
    function register(Doku_Event_Handler $controller) {
        $controller->register_hook('PARSER_CACHE_USE', 'BEFORE', $this, 'cache_callback_icalevents');
        $controller->register_hook('INDEXER_TASKS_RUN', 'AFTER', $this, 'handleIndexerTasks');
    }

    /**
     * Check if the last modified date in the local cache file is newer than the page and expire cache.
     *
     * @param Doku_Event $event  The event
     * @param array      $params The parameters for the event
     */
    function cache_callback_icalevents(Doku_Event &$event, $param) {
        global $INFO;

        $icalevents_meta =& $INFO['meta']['icalevents'];
        // if no metadata is set the icalevents is not used
        if(!isset($icalevents_meta)) {
            return;
        }

        // for each icalevents entry check if we have cache file and add it to depends
        foreach($icalevents_meta as ['url' => $entry]) {
            $cacheID = md5($entry);

            $file = new Cache($cacheID, '.ical');

            if(!file_exists($file->cache)) {
                $event->preventDefault();
                $event->stopPropagation();
                $event->result = false;

                return;
            }

            $event->data->depends['files'][] = $file->cache;
        }
    }

    public function handleIndexerTasks(Doku_Event $event, $param) {
        global $ID;

        $icalevents_meta = p_get_metadata($ID,'icalevents',METADATA_DONT_RENDER);
        foreach ($icalevents_meta as $key => &$element) {
            if(time() - $this->getConf('update_freq') >= $element['timestamp']) {
                $this->updateCalendarCache($element['url']);

                unset($icalevents_meta[$key]);
                $icalevents_meta[$key] = $element;

                $icalevents_meta[$key]['timestamp'] = time();

                p_set_metadata($ID,['icalevents' => $icalevents_meta],METADATA_DONT_RENDER);

                $event->stopPropagation();
                $event->preventDefault();
                return;
            }
        }
    }

    private function updateCalendarCache($url) {
        $cacheID = md5($url);
        $file = new Cache($cacheID, '.ical');
        $cachedContent = @file_get_contents($file->cache); // Suppress warning if file does not exist
        $cachedContent = preg_replace('/^DTSTAMP:.*$/m', '', $cachedContent);
        $cachedHash = md5($cachedContent);

        // TODO: move this to helper function (duplicate in rendering)
        $http = new DokuHTTPClient();
        if (!$http->get($url)) {
            $error = 'could not get ' . hsc($url) . ', HTTP status ' . $http->status . '. ';
            throw new Exception($error);
        }
        $newContent = $http->resp_body;
        $newContentFiltered = preg_replace('/^DTSTAMP:.*$/m', '', $cachedContent);

        $newHash = md5($newContentFiltered);

        if ($cachedHash !== $newHash) {
            file_put_contents($file->cache, $newContent);
            // Optionally, perform additional actions like logging or notifying about the update
        }
    }
}