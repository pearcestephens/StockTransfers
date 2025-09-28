<?php
declare(strict_types=1);

require_once $_SERVER['DOCUMENT_ROOT'].'/assets/functions/config.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/assets/functions/ApiResponder.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/assets/functions/JsonGuard.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/assets/functions/HttpGuard.php';

use Modules\Transfers\Stock\Services\NotesService;
use Modules\Transfers\Stock\Lib\AccessPolicy;

HttpGuard::allowMethods(['POST']);
HttpGuard::sameOriginOr([]);
HttpGuard::rateLimit('notes_add:'.(int)($_SESSION['userID']??0), 30, 60);
JsonGuard::csrfCheckOptional();
JsonGuard::idempotencyGuard();
HttpGuard::requireJsonContent();

if (empty($_SESSION['userID'])) ApiResponder::json(['success'=>false,'error'=>'Not authenticated'], 401);

$body = JsonGuard::readJson();
$transferId = (int)($body['transfer_id'] ?? 0);
$text = (string)($body['note_text'] ?? '');

if ($transferId <= 0 || trim($text) === '') {
    ApiResponder::json(['success'=>false,'error'=>'transfer_id and note_text required'], 400);
}
if (!AccessPolicy::canAccessTransfer((int)$_SESSION['userID'], $transferId)) {
    ApiResponder::json(['success'=>false,'error'=>'Forbidden'], 403);
}

$id = (new NotesService())->addTransferNote($transferId, $text, (int)$_SESSION['userID']);
ApiResponder::json(['success'=>true, 'note_id'=>$id], 200);
