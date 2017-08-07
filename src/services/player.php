<?php
class Player {
    /**
     * Get container object and dependencies.
     * $this->db: PDO object
     * $this->log: Monolog object
     */
    protected $c;
    protected $db;
    protected $log;
    public function __construct(Slim\Container $c) {
        $this->c = $c;
        $this->db = $c->db;
        $this->log = $c->logger;
        return;
    }

    protected $ownerId;
    protected $dayparts;
    protected $allocations;
    protected $subcompanies;

    /*
     * Get schedule will get the player schedule for sending to glow
     */
    public function getSchedule($displayId, $version, $mode, $reboot) {
        if ($mode === 'false') {
            $this->updateHeartbeat($version, $displayId);
            $this->resetReboot($reboot, $displayId);
            $this->clearOutage($displayId);
        }
        $this->ownerId = $this->getDisplayOwner($displayId);
        $this->dayparts = $this->getDayparts();
        $this->allocations = $this->getAllocations($this->ownerId);
        $this->createSubcompanyArray($this->allocations);

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
            $this->log->info("Master Playlist\n".print_r($daypart['masterPlaylist'], 1));
            $daypart['presentations'] = $this->generatePresentationCache($playlists);
            $output['schedule'][] = $daypart;
        }

        return json_encode($output);
    }


    private function getDisplayOwner($displayId) {
        try {
            $sql = $this->db->prepare("SELECT `ownerId` FROM `display` WHERE id = ?");
            $sql->execute(array($displayId));
            $rows = $sql->fetchAll(PDO::FETCH_ASSOC);
            return $rows[0]['ownerId'];
        } catch (PDOException $ex){
            $this->log->error("Database Error: ".$ex->getMessage());
            return 'error';
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
            $sql = $this->db->prepare("SELECT * FROM `allocations` WHERE `coid`= ?");
            $sql->execute(array($ownerId));
            $rows = $sql->fetchAll(PDO::FETCH_ASSOC);
            return $rows[0];
        } catch (PDOException $ex){
            $this->log->error("Database Error: ".$ex->getMessage());
            return 'error';
        }

    }

    private function createSubcompanyArray($allocations) {
        // Get owner allocation
        $this->subcompanies[] = array(
            'coid' => $allocations['coid'],
            'alloc' => round($allocations['owner'] * 2),
            'name' => 'Owner'
        );
        //Get each tenant allocation
        foreach (json_decode($allocations['json']) as $subco) {
            $this->subcompanies[] = array(
                'coid' => $subco->account,
                'alloc' => round($subco->allocation * 2),
                'name' => 'Tenant'
            );
        }
    }

    private function generatePresentationCache ($playlists) {
        $presentations = array();
        foreach ($playlists as $playlist) {
            foreach ($playlist['presentations'] as $presentation) {
                $presentations[$presentation['id']] = $presentation;
            }
        }
        return $presentations;
    }

    private function getPlaylist($daypart, $subco) {
        $playlist = [
            'id' => '',
            'alloc' => $subco['alloc'],
            'presentations' => [],
            'random' => true,
            'repeat' => true
        ];
        try {
            $sql = $this->db->prepare("SELECT `id`, `name`, `items`, `random`, `repeat` FROM `playlists` WHERE `coid`= ? AND `daypartId` LIKE ?");
            $sql->execute(array($subco['coid'], $daypart['id']));
            $rows = $sql->fetchAll(PDO::FETCH_ASSOC);

            // No assigned playlist
            if (!$sql->rowCount()) {
                $this->log->info("No rows returned from DB in getPlaylist. Returning default presentation for: ".$subco['coid'].".");
                $playlist['id'] = $subco['coid'];
                $playlist['random'] = '0';
                $playlist['repeat'] = '0';
                $playlist['presentations'] = $this->getDefaultPresentation($subco['coid']);
                return $playlist;
            }

            $playlist['id'] = $rows[0]['id'];
            $playlist['random'] = $rows[0]['random'];
            $playlist['repeat'] = $rows[0]['repeat'];
            $playlist['presentations'] = $this->getPresentations($rows[0]['items'], $subco['coid']);
            return $playlist;
        } catch (PDOException $ex){
            $this->log->error("Database Error: ".$ex->getMessage());
            return 'error';
        }

    }

    private function getPresentations($items, $coid) {
        $itemDB = unserialize($items);
        if (!is_array($itemDB)){return;}
        $presentations = array();
        foreach ($itemDB as $item) {
            $p = $this->getPresentation($item, $coid);
            if ($p) {$presentations[] = $p;}
        }
        if (count($presentations) == 0) {
            $this->log->info("No valid presentations found. Getting default presentation for ".$coid);
            $presentations = $this->getDefaultPresentation($coid);
        }
        return $presentations;
    }

    private function getDefaultPresentation($coid) {
        $presentations = array();
        try {
            $sql = $this->db->prepare("SELECT `defaultPres` FROM `accounts` WHERE `id`= ?");
            $sql->execute(array($coid));
            $rows = $sql->fetchAll(PDO::FETCH_ASSOC);
            if (!$sql->rowCount()) {
                $this->log->info("Could not find default presentation for ".$coid);
            } else {
                foreach ($rows as $row) {
                    if ($row['defaultPres'] !== '') {
                        $this->log->info("Returning default presentation: " . $row['defaultPres']);
                        $i = new Item();
                        $i->id = $row['defaultPres'];
                        $p = $this->getPresentation($i, $coid);
                        if ($p) {
                            $presentations[] = $p;
                        }
                    }
                }
            }
            return $presentations;
        } catch (PDOException $ex){
            $this->log->error("Database Error: ".$ex->getMessage());
            return 'error';
        }
    }

    private function getPresentation($item, $coid) {
        $this->log->info('Getting presentation '.$item->id);
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
            $sql = $this->db->prepare("SELECT p.json, p.name, p.origId, p.version, p.id, amax.status, p.tags
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
            $this->log->error("Database Error: ".$ex->getMessage());
            return 'error';
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
         *
         */

        $s = array(); // Unrandomized schedule list and allocation
        $pcache = array(); // playlist cache - tracks by presentationId
        $loops = array(); // for tracking loops by subcompany

        foreach ($playlists as $p) {
            //$this->log->info("Working on new playlist.".print_r($p, 1));
            $s[] = array($p['id'], $p['alloc']);
            $pcache[$p['id']] = $p;
            $pcache[$p['id']]['count'] = count($p['presentations']);
            $loops[$p['id']] = 0;
        }
        //$this->log->info("Passed presentation cache");
        $scheduleList = $this->randomizeArray($s);

        $schedule = array(); // Output array
        $seen = array(); // Track seen presentations for no repeat

        foreach ($scheduleList as $item) {
            $this->log->info("Current Playlist ".$item." Count: ".$loops[$item]);
            // No presentations? Skip 'em.
            if ($pcache[$item]['count'] == 0) {continue;}
            // Have we looped as many times as we have presentations? Reset the loop.
            if ($loops[$item] == $pcache[$item]['count']) {$loops[$item] = 0;}

            if ($pcache[$item]['random']) {
                $this->log->info("Randomizing play order");
                // Choose a number between 0 and the total number of presentations
                $rand = rand(0, ($pcache[$item]['count'] - 1));

                if ($pcache[$item]['repeat']) {
                    $this->log->info("With no repeats.");
                    if (!in_array($seen[$item], $seen)) {$seen[$item] = array();}
                    if (count($seen[$item]) == $pcache[$item]['count']) {
                        $seen[$item] = array(); // we've already seen all of the presentations
                    }
                    while (in_array($rand, $seen[$item])) {
                        $this->log->info("We've seen ".$rand.", getting new.");
                        $rand = rand(0, ($pcache[$item]['count'] - 1)); // We've already seen this presentation. get a new $rand
                    }
                    array_push($seen[$item], $rand);
                }

                $this->log->info("Rand is ".$rand);
                // So, this is the place where we get a presentation id to pass back to the main schedule.
                $this->log->info("Playing random. Pushing " . $pcache[$item]['presentations'][$rand]['id']);
                array_push($schedule, ['pid' => $pcache[$item]['presentations'][$rand]['id'], 'coid' => $pcache[$item]['presentations'][$rand]['coid']]);
                $loops[$item]++;
            } else {
                $this->log->info("Playing in order. Pushing " . $p['presentations'][$loops[$item]]['id']);
                array_push($schedule, ['pid' => $pcache[$item]['presentations'][$loops[$item]]['id'], 'coid' => $pcache[$item]['presentations'][$loops[$item]]['coid']]);
                $loops[$item]++;
            }
        };
        //$this->log->info('$schedule returns '.print_r($schedule, 1));
        return $schedule;
    }

    private function getReboot($display) {
        try {
            $sql = $this->db->prepare("select `reboot` FROM display WHERE `id` = ?");
            $sql->execute(array($display));
            $rows = $sql->fetchAll(PDO::FETCH_ASSOC);
            $this->log->info("Reboot status: ".$rows[0]['reboot']);
            if ($rows[0]['reboot']) {
                return 'true';
            } else {
                return 'false';
            }
        } catch (PDOException $ex){
            $this->log->error("Database Error: ".$ex->getMessage());
            return 'error';
        }

    }

    private function updateHeartbeat($version, $display) {
        try {
            $sql = $this->db->prepare("UPDATE `display` SET `heartbeat`=Now(), `version`= ? WHERE id = ?");
            $sql->execute(array($version, $display));
        } catch (PDOException $ex){
            $this->log->error("Database Error: ".$ex->getMessage());
            return 'error';
        }

    }

    private function resetReboot($reboot, $display) {
        // If this is the first player run, reset the reboot flag.
        if ($reboot == 'true') {
            try {
                $sql = $this->db->prepare("UPDATE `display` SET `reboot` = 0 WHERE id =  ?");
                $sql->execute(array($display));
            } catch (PDOException $ex){
                $this->log->error("Database Error: ".$ex->getMessage());
                return 'error';
            }
        }
    }

    private function clearOutage($display) {
        // Are we coming back from an outage?
        try {
            $sql = $this->db->prepare("SELECT `outage_id` FROM `display` WHERE id = ?");
            $rows = $sql->execute(array($display));

            if ($sql->rowCount() > 0) {
                $sql = $this->db->prepare("SELECT `outage_id` FROM `display` WHERE id = ?");
                $sql2 = $this->db->prepare("UPDATE `display` SET `outage_id`=0 WHERE id= ?");
                foreach ($rows as $row) {
                    $sql->execute(array($display));
                    $sql2->execute(array($display));
                }
            }
        } catch (PDOException $ex){
            $this->log->error("Database Error: ".$ex->getMessage());
            return 'error';
        }

    }

    private function getUpdate($version) {
        try {
            $sql = $this->db->query("select `player_version` FROM config");
            $rows = $sql->fetchAll(PDO::FETCH_ASSOC);
            if ($sql->rowCount()) {return false;}
            $curr_version = $rows[0]['player_version'];
            if ($curr_version > $version) {
                return true;
            } else {
                return false;
            }
        } catch (PDOException $ex){
            $this->log->error("Database Error: ".$ex->getMessage());
            return 'error';
        }

    }

    private function randomizeArray ($data) {
        //$this->log->info("Randomize array data: ".print_r($data, 1));

        // Step 1: Make an array of the playlist id's with repeats for count.
        $rarray = array();
        foreach ($data as $i => $v) {
            while ($data[$i][1] > 0) {
                array_push($rarray, $data[$i][0]);
                $data[$i][1]--;
            }
        }

        //$this->log->info("Weighted Array: ".print_r($rarray, 1));

        // Step 2: Randomize.
        // Try X number of loops with rarr and then just shuffle
        $stat = 1;
        $loopCount = 0;
        while ($stat == '1') {
            $stat = $this->rarr($rarray);
            $loopCount++;
            if($loopCount == 5) {
                //$this->log->info("rarr could not get a randomized list, shuffling.");
                shuffle($rarray);
                //$this->log->info(print_r($rarray, 1));
                return $rarray;
            }
        }
        //$this->log->info("rarr worked. Here's what we got back.\n".print_r($stat, 1));
        return $stat;
    }

    private function rarr ($a) {
        $r = array();
        $last = "";
        while ($a) {  // loop #2
            $l = count($a) - 1;
            $rand = rand(0, $l);
            if ($a[$rand] != $last) {
                // Nope. Transfer to our $r array.
                array_push($r, $a[$rand]);
                $last = $a[$rand];
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