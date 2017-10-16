<?php
class Player {
    /**
     * Get container object and dependencies.
     * $this->c->db: PDO object
     * $this->c->logger: Monolog object
     */
    protected $c;
    public function __construct(Slim\Container $c) {
        $this->c = $c;
        return;
    }

    protected $ownerId;
    protected $dayparts;
    protected $allocations;
    protected $subcompanies;
    protected $lastMod;



    /*
     * Get schedule will get the player schedule for sending to glow
     */
    public function getSchedule($displayId, $version, $mode, $reboot) {
        $this->lastMod = date("D M j G:i:s");
        if ($mode === 'false') {
            $this->updateHeartbeat($version, $displayId);
            $this->resetReboot($reboot, $displayId);
            $this->clearOutage($displayId);
        }
        $this->ownerId = $this->getDisplayOwner($displayId);
        $this->dayparts = $this->getDayparts();
        $this->allocations = $this->getAllocations($this->ownerId);
        $this->subcompanies = $this->createSubcompanyArray($this->allocations);

        /*
         *  Step 1: Create subcompany array needs to return the allocation for multi-display.
         */

        $output = array(
            'schedule' => [],
            'ani_allow' => true,
            'trans_allow' => true,
            'restart' => false,
            'reboot' => $this->getReboot($displayId),
            'update' => $this->getUpdate($version)
        );
        foreach ($this->dayparts as $d) {
            $playlists = array();
            $daypart = [
                'masterPlaylist' => [],
                'presentations' => [],
                'id' => $d['id'],
                'days' => $d['days'],
                'start' => $d['start'],
                'stop' => $d['stop'],
                'lastMod' => $d['lastMod']
            ];

            /*
             *  Step 2: get playlist has to understand multi display
             */
            foreach ($this->subcompanies as $s) {
                $playlists[] = $this->getPlaylist($d, $s);
            }

            /*
             *  Step 3: generate master playlist has to be able to handle it.
             */
            $daypart['masterPlaylist'] = $this->generatePlaylist($playlists);
            $this->c->logger->info("Master Playlist\n".print_r($daypart['masterPlaylist'], 1));
            $daypart['presentations'] = $this->generatePresentationCache($playlists);
            $output['schedule'][] = $daypart;
        }

        return $output;
    }


    private function getDisplayOwner($displayId) {
        try {
            $sql = $this->c->db->prepare("SELECT `ownerId` FROM `display` WHERE id = ?");
            $sql->execute(array($displayId));
            $rows = $sql->fetchAll(PDO::FETCH_ASSOC);
            return $rows[0]['ownerId'];
        } catch (PDOException $ex){
            $this->c->logger->error("Database Error: ".$ex->getMessage());
            return array('stat' => 'error', 'message' =>  $ex->getMessage());
        }

    }

    /*
     * Dayparts have been deprecated, so we just return an "all day, all week" dummy.
     */
    private function getDayparts() {
        $dayparts[] = array(
            'id' => '0',
            'coid' => '0',
            'name' => 'Default',
            'start' => '2014-09-16 00:00:00',
            'stop' => '2014-09-17 00:00:00',
            'days' => 'm,tu,w,th,f,sa,su',
            'lastMod' => '0'
        );
        return $dayparts;
    }

    // TODO: This should be by display ID not company ID. Support multiple displays per company.
    private function getAllocations($ownerId) {
        try {
            $sql = $this->c->db->prepare("SELECT * FROM `allocations` WHERE `coid`= ?");
            $sql->execute(array($ownerId));
            $rows = $sql->fetchAll(PDO::FETCH_ASSOC);
            return $rows[0];
        } catch (PDOException $ex){
            $this->c->logger->error("Database Error: ".$ex->getMessage());
            return array('stat' => 'error', 'message' =>  $ex->getMessage());
        }

    }

    private function createSubcompanyArray($allocations) {
        // Get owner allocation
        $subcompanies = array();
        $subcompanies[] = array(
            'coid' => $allocations['coid'],
            'alloc' => round($allocations['owner'] * 2),
            'name' => 'Owner',
            'type' => 'single',
            'count' => 1
        );
        // Create allocation for multi
        // After this, we "getPlaylists"
        $this->subcompanies[] = array(
            'coid' => '',
            'alloc' => round($allocations['multi'] * 2),
            'name' => 'Window Pane',
            'type' => 'multi'
        );
        //Get each tenant allocation
        foreach (json_decode($allocations['json']) as $subco) {
            $subcompanies[] = array(
                'coid' => $subco->account,
                'alloc' => round($subco->allocation * 2),
                'name' => 'Tenant',
                'type' => 'single',
                'count' => 1
            );
        }

        // Multi alloc is added as a playlist.
        // Normally these are chosen by company id, we will pass through a dummy.
        if ($allocations['multi'] > 0) {
            $subcompanies[] = array(
                'coid' => 'MU1',
                'alloc' => round($allocations['multi'] * 2),
                'name' => 'MultiPane',
                'type' => '4up',
                'count' => 4
            );
        }
        return $subcompanies;
    }

    private function generatePresentationCache ($playlists) {
        $presentations = array();
        foreach ($playlists as $playlist) {
            foreach ($playlist['presentations'] as $presentation) {
                $presentations[$presentation['presentation']['id']] = $presentation;
            }
        }
        return $presentations;
    }

    private function getPlaylist($daypart, $subco) {
        // Default
        $playlist = [
            'id' => '',
            'alloc' => $subco['alloc'],
            'presentations' => [],
            'random' => false,
            'repeat' => true,
            'type' => $subco['type'],
            'count' => 0,
            'presentationCount' => $subco['count']
        ];
        // Multi-Pane only
        if ($subco['coid'] === 'MU1') {
            $playlist['id'] = $subco['coid'];

            // Step 1: Get a list of companies that are window pane only
            try{
                $sql = $this->c->db->prepare("SELECT id, defaultPres FROM `accounts` WHERE  `multi` = 1 AND  `parentId` = ?");
                $sql->execute(array($this->ownerId));
                $rows = $sql->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as $row) {
                    if ($row['presId'] === '' || $row["id"] === '') {
                        continue;
                    }
                    $i = new Item();
                    $i->id = $row['defaultPres'];
                    $p = $this->getPresentation($i, $row["id"]);
                    $playlist['presentations'][] = array('companyId' => $row["id"], 'presentation' => $p);
                    $playlist['count'] = count($playlist['presentations']);
                }
                // Were assuming this is good so far
                //$this->c->logger->info("Returning multipane playlist. ".print_r($playlist, 1));
                return $playlist;
            } catch (PDOException $ex){
                $this->c->logger->error("Database Error: ".$ex->getMessage());
                return array('stat' => 'error', 'message' =>  $ex->getMessage());
            }
        } else {
            try {
                $sql = $this->c->db->prepare("SELECT `id`, `name`, `items`, `random`, `repeat` FROM `playlists` WHERE `coid`= ? AND `daypartId` LIKE ?");
                $sql->execute(array($subco['coid'], $daypart['id']));
                $rows = $sql->fetchAll(PDO::FETCH_ASSOC);

                // No assigned playlist
                if (!$sql->rowCount()) {
                    $this->c->logger->info("No rows returned from DB in getPlaylist. Returning default presentation for: ".$subco['coid'].".");
                    $playlist['id'] = $subco['coid'];
                    $playlist['random'] = '0';
                    $playlist['repeat'] = '0';
                    $playlist['presentations'] = $this->getDefaultPresentation($subco['coid']);
                    $playlist['count'] = count($playlist['presentations']);
                    return $playlist;
                }

                $playlist['id'] = $rows[0]['id'];
                $playlist['random'] = $rows[0]['random'];
                $playlist['repeat'] = $rows[0]['repeat'];
                $playlist['presentations'] = $this->getPresentations($rows[0]['items'], $subco['coid']);
                $playlist['count'] = count($playlist['presentations']);
                return $playlist;
            } catch (PDOException $ex){
                $this->c->logger->error("Database Error: ".$ex->getMessage());
                return array('stat' => 'error', 'message' =>  $ex->getMessage());
            }
        }
    }

    private function getPresentations($items, $coid) {
        $itemDB = unserialize($items);
        if (!is_array($itemDB)){return 0;}
        $presentations = array();
        foreach ($itemDB as $item) {
            $p = $this->getPresentation($item, $coid);
            //if ($p) {$presentations[] = $p;}
            if ($p) {$presentations[] = array('companyId' => $coid, 'presentation' => $p);}
        }
        if (count($presentations) == 0) {
            $this->c->logger->info("No valid presentations found. Getting default presentation for Company Id".$coid);
            $presentations = $this->getDefaultPresentation($coid);
        }
        //$this->c->logger->info("\n\nReturning playlist ".print_r($presentations, 1));
        return $presentations;
    }


    private function getDefaultPresentation($coid) {
        $presentations = array();
        try {
            $sql = $this->c->db->prepare("SELECT `defaultPres` FROM `accounts` WHERE `id`= ?");
            $sql->execute(array($coid));
            $rows = $sql->fetchAll(PDO::FETCH_ASSOC);
            if (!$sql->rowCount()) {
                $this->c->logger->info("Could not find default presentation for ".$coid);
            } else {
                foreach ($rows as $row) {
                    if ($row['defaultPres'] !== '') {
                        //$this->c->logger->info("Returning default presentation: " . $row['defaultPres']);
                        $i = new Item();
                        $i->id = $row['defaultPres'];
                        $p = $this->getPresentation($i, $coid);
                        if ($p) {
                            //$presentations[] = $p;
                            $presentations[] = array('companyId' => $coid, 'presentation' => $p);
                        }
                    }
                }
            }
            //$this->c->logger->info("\n\nReturning default presentation ".print_r($presentations, 1));
            return $presentations;
        } catch (PDOException $ex){
            $this->c->logger->error("Database Error: ".$ex->getMessage());
            return array('stat' => 'error', 'message' =>  $ex->getMessage());
        }
    }


    /*
     *  This gets and caches the JSON for a presentation to be passed with the player object.
     */
    private function getPresentation($item, $coid) {
        //$this->c->logger->info('Getting presentation '.$item->id);
        $presentation = [
            'id' => $item->id,
            'json' => [],
            'weight' => $item->weight,
            'name' => '',
            'tags' => '',
            'version' => 1,
            'coid' => $coid
        ];

        try{
            $sql = $this->c->db->prepare("SELECT p.json, p.name, p.origId, p.version, p.id, amax.status, p.tags
            FROM presentations as p
            inner join(
                SELECT origId, MAX(version) as ver FROM presentations WHERE approval != '0' group by origId
                      ) max on p.origId = max.origId AND p.version = max.ver
            LEFT JOIN(
                SELECT presId, id, MAX(status) as status FROM approval WHERE status != 0 GROUP BY presId
            ) amax on p.origId = amax.presId
            WHERE p.origId = ?");
            $sql->execute(array($item->id));
            $rows = $sql->fetchAll(PDO::FETCH_ASSOC);

            if (!$sql->rowCount()) {
                return false;
            } else {
                foreach ($rows as $row) {
                    if ($row['status'] == NULL) {return false;}
                    $presentation['version'] = $row['version'];
                    $presentation['json'] = $row['json'];
                    $presentation['name'] = $row['name'];
                    $presentation['tags'] = $row['tags'];
                }
                return $presentation;
            }
        } catch (PDOException $ex){
            $this->c->logger->error("Database Error: ".$ex->getMessage());
            return array('stat' => 'error', 'message' =>  $ex->getMessage());
        }
    }

    /*
     * Memo to myself:
     * This all works by making up an array of the various playlist id's and allocations.
     * that is sent through randomizeArray which sends back a list of 200 playlist Id's,
     *  allocated and shuffled.
     *
     * from there we go into each playlist and choose a presentation id based on the random/repeat, etc...
     * finally we send back a 200 item list of presentation Id's for play.
     *
     * Still need to deal with weighting in here.
     */
    private function generatePlaylist($playlists) {
        /*
         * Better document what's going on in here
         *  So, it seems to me like I could get more mileage out of making this an array of objects.
         */

        $s = array(); // Unrandomized schedule list (playlist id,  allocation)
        $pcache = array(); // playlist cache - tracks by [playlistId][presentationId]
        $loops = array(); // for tracking loops by playlistId

        /*
         * Here we set up the array of playlists and allocations for our randomizer.
         * we also set up a cache of playlists by their ids and store each one's length.
         * Finally, we set up the number of times we've looped over each playlist.
         */
        foreach ($playlists as $p) {
            //$this->c->logger->info("Working on new playlist.".print_r($p, 1));
            // Get a list of playlist id's and allocations.

            // TODO: Need to add presentation count and also return that from Randomizer below.
            $s[] = array('pid' => $p['id'], 'allocation' => $p['alloc'], 'type' => $p['type'], 'count' => $p['count'], 'presentationCount' => $p['presentationCount']);

            $pcache[(string)$p['id']] = $p;
            $pcache[(string)$p['id']]['count'] = count($p['presentations']);
            $loops[(string)$p['id']] = 0;
        }
        // Presentation cache is good.
        $this->c->logger->info("\n\nPassed presentation cache.\n\nMulti pcache is: ".print_r($pcache['MU1'], 1));
        $this->c->logger->info("\n\nPassed presentation cache.\n\n123 pcache is: ".print_r($pcache['123'], 1));
        /*
         * Our final schedule list comes back randomized.
         * It's an array of playlist Id's and allocations.
         */
        $scheduleList = $this->randomizeArray($s);

        // Schedule list is good
        //$this->c->logger->info("Randomized schedule returned.".print_r($scheduleList, 1));

        /*
         * Here's where we choose the next presentation in line.
         */
        $schedule = array(); // Output array
        //$seen = array(); // Track seen presentations for no repeat

        foreach ($scheduleList as $item) {

            //$this->c->logger->info("Current Playlist ".$item['pid']." Count: ".$loops[(string)$item['pid']]."\n\n
            //current item is ".print_r($item, 1));

            // No presentations? Skip 'em.
            if (count($pcache[(string)$item['pid']]['presentations']) === 0) {
                $this->c->logger->info("No presentations in playlist ".$item['pid']." Count was ".count($pcache[(string)$item['pid']]['presentations'])."\n\ns: ".print_r($pcache,1));
                continue;
            }

            $count = $item['presentationCount'];
            $presentations = array();
            while ($count > 0) {
                // Have we looped as many times as we have presentations? Reset the loop.
                if ($loops[(string)$item['pid']] == count($pcache[(string)$item['pid']]['presentations'])) {
                    $loops[(string)$item['pid']] = 0;
                }
                $this->c->logger->info('Choosing presentation. Count is: '.$count);
                // while($this->choosePresentation($pcache, $item['pid'], $loops) !== $seen){}
                $presentations[] = $this->choosePresentation($pcache, $item['pid'], $loops);
                $loops[(string)$item['pid']]++;
                $count--;
            }
            $this->c->logger->info('Presentation chosen. presentation is: '.print_r($presentations, 1));
            $scheduleItem = array(
                'presentations' => $presentations,
                'type' => $item['type'],
                'count' => $item['presentationCount']
            );
            array_push($schedule, $scheduleItem);

        };
        // $this->c->logger->info('$schedule returns '.print_r($schedule, 1));
        return $schedule;
    }

    // OK, so the remainder of our bug lies here.... But also the best place to improve our code flow.
    private function choosePresentation($pcache, $item, $loop) {
            // Here's where we randomize the order in which we play the playlist
            $this->c->logger->info('In ChoosePresentation. Item is '.$item);
            if ($pcache[$item]['random']) {
                $this->c->logger->info("Randomizing play order");
                // Choose a number between 0 and the total number of presentations
                $rand = rand(0, ($pcache[(string)$item]['count'] - 1));
                $this->c->logger->info("Rand is ".$rand." Count is ".$pcache[(string)$item]['count']);

                /*if ($pcache[$item]['repeat']) {
                    //$this->c->logger->info("With no repeats.");
                    if (!in_array($seen[$item], $seen)) {$seen[$item] = array();}
                    if (count($seen[$item]) == $pcache[$item]['count']) {
                        $seen[$item] = array(); // we've already seen all of the presentations
                    }
                    while (in_array($rand, $seen[$item])) {
                        $this->c->logger->info("We've seen ".$rand.", getting new.");
                        $rand = rand(0, ($pcache[$item]['count'] - 1)); // We've already seen this presentation. get a new $rand
                    }
                    array_push($seen[$item], $rand);
                }*/

                /*
                 *  Here's to the root... How do I verify the cache I'm pulling from.
                 */

                // So, this is the place where we get a presentation id to pass back to the main schedule.
                $this->c->logger->info("Playing random. Pushing " . $pcache[(string)$item]['presentations'][$rand]['presentation']['id']." coid: ".$pcache[(string)$item]['presentations'][$rand]['presentation']['coid']);
                return array('pid' => $pcache[(string)$item]['presentations'][$rand]['presentation']['id'], 'coid' => $pcache[(string)$item]['presentations'][$rand]['presentation']['coid']);
            } else {
                // Or we do it here if were just playing in order.
                $this->c->logger->info("Playing in order. Pushing " . $pcache[(string)$item]['presentations'][$loop[(string)$item]]['presentation']['id'] . " coid: " .$pcache[(string)$item]['presentations'][$loop[(string)$item]]['presentation']['coid']);
                return  array('pid' => $pcache[(string)$item]['presentations'][$loop[(string)$item]]['presentation']['id'], 'coid' => $pcache[(string)$item]['presentations'][$loop[(string)$item]]['presentation']['coid']);
            }
    }

    private function getReboot($display) {
        try {
            $sql = $this->c->db->prepare("select `reboot` FROM display WHERE `id` = ?");
            $sql->execute(array($display));
            $rows = $sql->fetchAll(PDO::FETCH_ASSOC);
            $this->c->logger->info("Reboot status: ".$rows[0]['reboot']);
            if ($rows[0]['reboot']) {
                return 'true';
            } else {
                return 'false';
            }
        } catch (PDOException $ex){
            $this->c->logger->error("Database Error: ".$ex->getMessage());
            return array('stat' => 'error', 'message' =>  $ex->getMessage());
        }

    }

    private function updateHeartbeat($version, $display) {
        try {
            $sql = $this->c->db->prepare("UPDATE `display` SET `heartbeat`=Now(), `version`= ? WHERE id = ?");
            $sql->execute(array($version, $display));
        } catch (PDOException $ex){
            $this->c->logger->error("Database Error: ".$ex->getMessage());
            return array('stat' => 'error', 'message' =>  $ex->getMessage());
        }

    }

    private function resetReboot($reboot, $display) {
        // If this is the first player run, reset the reboot flag.
        if ($reboot == 'true') {
            try {
                $sql = $this->c->db->prepare("UPDATE `display` SET `reboot` = 0 WHERE id =  ?");
                $sql->execute(array($display));
            } catch (PDOException $ex){
                $this->c->logger->error("Database Error: ".$ex->getMessage());
                return array('stat' => 'error', 'message' =>  $ex->getMessage());
            }
        }
    }

    private function clearOutage($display) {
        // Are we coming back from an outage?
        try {
            $sql = $this->c->db->prepare("SELECT `outage_id` FROM `display` WHERE id = ?");
            $rows = $sql->execute(array($display));

            if ($sql->rowCount() > 0) {
                $sql = $this->c->db->prepare("SELECT `outage_id` FROM `display` WHERE id = ?");
                $sql2 = $this->c->db->prepare("UPDATE `display` SET `outage_id`=0 WHERE id= ?");
                foreach ($rows as $row) {
                    $sql->execute(array($display));
                    $sql2->execute(array($display));
                }
            }
        } catch (PDOException $ex){
            $this->c->logger->error("Database Error: ".$ex->getMessage());
            return array('stat' => 'error', 'message' =>  $ex->getMessage());
        }

    }

    private function getUpdate($version) {
        try {
            $sql = $this->c->db->query("select `player_version` FROM config");
            $rows = $sql->fetchAll(PDO::FETCH_ASSOC);
            if ($sql->rowCount()) {return false;}
            $curr_version = $rows[0]['player_version'];
            if ($curr_version > $version) {
                return true;
            } else {
                return false;
            }
        } catch (PDOException $ex){
            $this->c->logger->error("Database Error: ".$ex->getMessage());
            return array('stat' => 'error', 'message' =>  $ex->getMessage());
        }

    }


    // TODO: Need to update this to work with new object
    private function randomizeArray ($data) {
        //$this->c->logger->info("Randomize array data: ".print_r($data, 1));

        // Step 1: Make an array of the playlist id's with repeats for count.
        $rarray = array();
        foreach ($data as $i => $v) {
            while ($data[$i]['allocation'] > 0) {
                array_push($rarray, $data[$i]);
                $data[$i]['allocation']--;
            }
        }

        // $this->c->logger->info("Weighted Array: ".print_r($rarray, 1));

        // Step 2: Randomize.
        // Try X number of loops with rarr and then just shuffle
        $stat = 1;
        $loopCount = 0;
        while ($stat == '1') {
            $stat = $this->rarr($rarray);
            $loopCount++;
            if($loopCount == 5) {
                //$this->c->logger->info("rarr could not get a randomized list, shuffling.");
                shuffle($rarray);
                //$this->c->logger->info(print_r($rarray, 1));
                return $rarray;
            }
        }
        //$this->c->logger->info("rarr worked. Here's what we got back.\n".print_r($stat, 1));
        return $stat;
    }

    private function rarr ($a) {
        $r = array();
        $last = "";
        while ($a) {  // loop #2
            $l = count($a) - 1;
            $rand = rand(0, $l);
            if ($a[$rand]['id'] != $last) {
                // Nope. Transfer to our $r array.
                array_push($r, $a[$rand]);
                $last = $a[$rand]['id'];
                array_splice($a, $rand, 1);
            } else {
                // We have a match between our "last" presentation and the one we just selected.
                // We need to scan our array and verify that we still have different values.
                /*$check = $a[$rand];
                foreach ($a as $c) {
                    if ($check != $c) {
                        // We have at least 2 different values in our array still.
                        // Rerun the while loop
                        continue 2;
                    }
                }*/
                // All of the values left in our array are repeats.
                // That's no good, so return an error and rerun.
                return 1;
            }
        }
        // Everything is awesome. Return the randomized array.
        return $r;
    }
}