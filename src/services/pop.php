<?php
class POP
{
    protected $c;

    /**
     * Get our container and all of our stuff...
     */
    public function __construct(Slim\Container $c) {
        $this->c = $c;
        return;
    }

    public function storePop($displayId, $popData) {
        foreach ($popData as $popentry) {
            $this->c->logger->info("Working on new POP entry ".print_r($popentry, 1));
            $time = date( 'Y-m-d H:i:s', $popentry['time'] );
            try {
                $sql = $this->c->db->prepare("SELECT * FROM `pop` WHERE `displayId` = ? AND `time` = ? AND `presId` = ?");
                $sql->execute(array($displayId, $time, $popentry['presId']));
            } catch (PDOException $ex){
                $this->c->logger->error("Database Error: ".$ex->getMessage());
                return 'error';
            }

            if ($sql->rowCount() < 1) {
                try {
                    $sql2 = $this->c->db->prepare(
                        "INSERT INTO `pop` (`displayId`, `time`, `duration`, `presId`, `version`, `coid`, `count`) VALUES (:displayId, :time, :duration, :presId, :version, :coid, :count)"
                    );
                    $sql2->execute(array(
                        ':displayId' => $popentry['displayId'],
                        ':time' => $time,
                        ':duration' => $popentry['duration'],
                        ':presId' => $popentry['presId'],
                        ':version' => $popentry['version'],
                        ':coid' => $popentry['coid'],
                        ':count' => $popentry['count']
                    ));
                } catch (PDOException $ex){
                    $this->c->logger->error("Database Error: ".$ex->getMessage());
                    return array('stat' => 'error', 'message' =>  $ex->getMessage());
                }
            } else {
                $this->c->logger->info("Duplicate POP entry filtered.\n".print_r($popentry, 1));
            }
        }

        $this->c->logger->info("Success: POP script completed.\n\n");
        $response_array['status'] = 'success';
        return $response_array;
    }
}