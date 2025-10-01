<?php
declare(strict_types=1);
namespace Modules\Transfers\Stock\Services;

use PDO;
use Throwable;

final class StaffNameResolver
{
    private PDO $db;
    
    public function __construct()
    { 
        $this->db = cis_pdo(); 
    }
    public function name(int $userId): ?string
    {
        if($userId<=0) return null;
        try {
            $st=$this->db->prepare("SELECT first_name, last_name, display_name, username FROM users WHERE id=:id LIMIT 1");
            $st->execute(['id'=>$userId]);
            $r=$st->fetch(PDO::FETCH_ASSOC); if(!$r) return null;
            $candidates=[ $r['display_name']??null, trim(($r['first_name']??'').' '.($r['last_name']??'')), $r['username']??null ];
            foreach($candidates as $c){ $c=trim((string)$c); if($c!=='') return $c; }
            return null;
        } catch(Throwable $e){ return null; }
    }
}
?>