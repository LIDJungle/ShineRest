<?php
class Display {
    protected $c;

    /**
     * Get our container and all of our stuff...
     */
    public function __construct(Slim\Container $c) {
        $this->c = $c;
        return;
    }

    /*
     * Returns player params from database.
     *
     */
    public function getDisplayParam($displayId) {
        try {
            $sql = $this->c->db->prepare("SELECT * FROM `display` WHERE id = ?");
            $sql->execute(array($displayId));
            $rows = $sql->fetchAll(PDO::FETCH_ASSOC);
            $data = $rows[0];
            $data['w'] = $data['dim_w'];
            $data['h'] = $data['dim_h'];
            $data['cr'] = $data['crate'];
            $data['coid'] = $data['ownerId'];
            $data['stat'] = 'success';
        } catch (PDOException $ex){
            $data['stat'] = 'error';
            $data['message'] = $ex->getMessage();
            $this->c->logger->error("Database Error: ".$ex->getMessage());
        }
        return $data;
    }
}