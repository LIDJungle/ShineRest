<?php
class Display {
    protected $c;
    protected $db;
    protected $log;

    /**
     * Get our container and all of our stuff...
     */
    public function __construct(Slim\Container $c) {
        $this->c = $c;
        $this->db = $c->db;
        $this->log = $c->logger;
        return;
    }

    /*
     * Returns player params from database.
     * TODO: Update ajax calls in player and app to use the database defined array for easier pass through.
     *
     */
    protected  $data;
    public function getDisplayParam($displayId) {
        try {
            $sql = $this->db->prepare("SELECT * FROM `display` WHERE id = ?");
            $sql->execute(array($displayId));
            $rows = $sql->fetchAll(PDO::FETCH_ASSOC);
            $this->data = $rows[0];
            $this->data['w'] = $this->data['dim_w'];
            $this->data['h'] = $this->data['dim_h'];
            $this->data['cr'] = $this->data['crate'];
            $this->data['coid'] = $this->data['ownerId'];
            $this->data['status'] = 'success';
        } catch (PDOException $ex){
            $this->data['status'] = 'error';
            $this->data['message'] = $ex->getMessage();
        }
        return json_encode($this->data);
    }
}