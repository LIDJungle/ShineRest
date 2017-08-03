<?php
class Player {
    protected $c;
    protected $ownerId;
    protected $dayparts;
    protected $allocations;
    protected $subcompanies;

    /**
     * Get our container and all of our stuff...
     */
    public function __construct(Slim\Container $c) {
        $this->c = $c;
        return;
    }

    /*
     * Test function just to make sure I know what I'm doing.
     */
    public function getPlayer() {
        $sql = $this->c->db->prepare("SELECT `ownerId` FROM `display` WHERE id = ?");
        $sql->execute(array('100002'));
        return $sql->fetchAll(PDO::FETCH_ASSOC);
    }

    /*
     * Get schedule will get the player schedule for sending to glow
     */
    public function getSchedule($displayId) {
        $this->ownerId = $this->getDisplayOwner($displayId);
        $this->dayparts = $this->getDayparts();
        $this->allocations = $this->getAllocations($this->ownerId);
        $this->createSubcompanyArray($this->allocations);
    }

    public function getDisplayOwner($displayId) {
        $sql = $this->c->db->prepare("SELECT `ownerId` FROM `display` WHERE id = ?");
        $sql->execute(array($displayId));
        $rows = $sql->fetchAll(PDO::FETCH_ASSOC);
        return $rows[0]['ownerId'];
    }

    /*
     * Dayparts have been depricated, so we just return an "all day, all week" dummy.
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
        $sql = $this->c->db->prepare("SELECT * FROM `allocations` WHERE `coid`= ?");
        $sql->execute(array($ownerId));
        $rows = $sql->fetchAll(PDO::FETCH_ASSOC);
        return $rows[0];
    }

    private function createSubcompanyArray($allocations) {
        // Get owner allocation
        $this->subcompanies[] = array(
            'coid' => $allocations['coid'],
            'alloc' => round($allocations['owner'] * 2),
            'name' => 'Owner'
        );
        //Get each tenant allocation
        foreach($allocations['json'] as $subco) {
            $this->subcompanies[] = array(
                'coid' => $subco->account,
                'alloc' => round($subco->allocation * 2),
                'name' => 'Tenant'
            );
        }
    }

    private function generatePresentationCache ($playlists, $log) {
        $presentations = array();
        // TODO: Need to handle empty subcos more gracefully.
        // Getting errors in the PHP log.
        foreach ($playlists as $playlist) {
            foreach ($playlist['presentations'] as $presentation) {
                $presentations[$presentation['id']] = $presentation;
            }
        }
        return $presentations;
    }


}