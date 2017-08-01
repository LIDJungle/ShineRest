<?php
class Player {
    protected $c;

    /**
     * @return mixed
     */
    public function __construct(Slim\Container $c)
    {
        $this->c = $c;
        return;
    }

    public function getPlayer() {
        $sql = $this->c->db->query("SELECT * FROM test");
        return $sql->fetchAll(PDO::FETCH_ASSOC);
    }
}