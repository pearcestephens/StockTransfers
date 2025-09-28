<?php
/** @var array|null $transfer */
$tx      = $transfer ?: ['id' => 0, 'outlet_from' => '', 'outlet_to' => '', 'items' => []];
$txId    = (int)($tx['id'] ?? 0);
$items   = is_array($tx['items'] ?? null) ? $tx['items'] : [];
$fromLbl = htmlspecialchars((string)($tx['outlet_from'] ?? ''));
$toLbl   = htmlspecialchars((string)($tx['outlet_to'] ?? ''));
?>
<div class="animated fadeIn">
  <div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
      <div>
        <h4 class="card-title mb-0">
          Receive Transfer #<?= $txId ?><br>
          <small class="text-muted"><?= $fromLbl ?> → <?= $toLbl ?></small>
        </h4>
        <div class="small text-muted">Enter received quantities (cannot exceed sent)</div>
      </div>
      <div class="btn-group">
        <button id="saveReceive" class="btn btn-success">
          <i class="fa fa-check mr-1"></i> Save Receive
        </button>
      </div>
    </div>

    <div class="card-body">
      <!-- Summary -->
      <div class="d-flex align-items-center flex-wrap mb-3" style="gap:10px;">
        <span>Lines: <strong id="linesCount"><?= count($items) ?></strong></span>
        <span>Sent total: <strong id="sentTotal">0</strong></span>
        <span>Received total: <strong id="recvTotal">0</strong></span>
        <span>Remaining: <strong id="remainingTotal">0</strong></span>
      </div>

      <div class="card tfx-card-tight">
        <div class="card-body py-2">
          <table class="table table-responsive-sm table-bordered table-striped table-sm" id="receive-table">
            <thead>
              <tr>
                <th style="width:38px;"></th>
                <th>Product</th>
                <th>Sent</th>
                <th>Received</th>
                <th>Remaining</th>
                <th>ID</th>
              </tr>
            </thead>
            <tbody>
            <?php
            $row = 0;
            foreach ($items as $i) {
              $row++;
              $iid   = (int)$i['id'];
              $pid   = htmlspecialchars((string)($i['product_id'] ?? ''));
              $sent  = (int)($i['qty_sent_total'] ?? 0);
              $recv  = (int)($i['qty_received_total'] ?? 0);
              $rem   = max(0, $sent - $recv);

              echo '<tr data-sent="'.$sent.'">';
              echo   "<td class='text-center align-middle'>
                        <span class='text-muted'>#</span>
                        <input type='hidden' class='itemID' value='{$iid}'>
                      </td>";
              echo   '<td>'.($pid ?: 'Product').'</td>';
              echo   '<td class="sent">'.$sent.'</td>';
              echo   "<td class='recv-td'>
                        <input type='number' min='0' max='{$sent}' value='".($recv ?: 0)."' class='tfx-num'>
                      </td>";
              echo   '<td class="rem">'.$rem.'</td>';
              echo   '<td><span class="id-counter">'.$txId.'-'.$row.'</span></td>';
              echo '</tr>';
            }
            if (!$items) {
              echo '<tr><td colspan="6" class="text-center text-muted py-4">No items to receive.</td></tr>';
            }
            ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Hidden context for JS -->
      <input type="hidden" id="transferID" value="<?= $txId ?>">
      <input type="hidden" id="staffID" value="<?= (int)($_SESSION['userID'] ?? 0) ?>">
    </div>
  </div>
</div>
