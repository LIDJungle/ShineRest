<?php
class POP
{
    protected $c;
    protected $db;
    protected $log;

    /**
     * Get our container and all of our stuff...
     */
    public function __construct(Slim\Container $c) {
        $this->c = $c;
        $this->db = $c->db;
        $this->log = $$c->logger;
        return;
    }

    public function storePop($displayId, $popData) {
        foreach ($popData as $popentry) {
            $time = date( 'Y-m-d H:i:s', $popentry['time'] );
            try {
                $sql = $this->c->db->prepare("SELECT * FROM `pop` WHERE `displayId` = ? AND `time` = ?");
                $sql->execute(array($displayId, $time));
            } catch (PDOException $ex){
                $this->log->error("Database Error: ".$ex->getMessage());
                return 'error';
            }

            if ($sql->rowCount() < 1) {
                try {
                    $sql2 = $this->c->db->prepare(
                        "INSERT INTO `pop` (`displayId`, `time`, `duration`, `presId`, `version`, `coid`) VALUES (:displayId, :time, :duration, :presId, :version, :coid)"
                    );
                    $sql2->execute(array(
                        ':displayId' => $popentry['displayId'],
                        ':time' => $time,
                        ':duration' => $popentry['duration'],
                        ':presId' => $popentry['presId'],
                        ':version' => $popentry['version'],
                        ':coid' => $popentry['coid']
                    ));
                } catch (PDOException $ex){
                    $this->log->error("Database Error: ".$ex->getMessage());
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