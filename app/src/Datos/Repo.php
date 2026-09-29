<?php
namespace LibertyFin\Datos;

use PDO;

abstract class Repo
{
    protected $db;
    public function __construct(PDO $db) { $this->db = $db; }

    protected function todos($sql, array $p = [])
    { $st = $this->db->prepare($sql); $st->execute($p); return $st->fetchAll(); }

    protected function uno($sql, array $p = [])
    { $st = $this->db->prepare($sql); $st->execute($p); $r = $st->fetch(); return $r ?: null; }

    protected function valor($sql, array $p = [])
    { $st = $this->db->prepare($sql); $st->execute($p); return $st->fetchColumn(); }
}
