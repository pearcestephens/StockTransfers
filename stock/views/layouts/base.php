<?php require_once __DIR__ . '/../../bootstrap.php';
/** @var string $pageTitle */
/** @var string $content   */
/** @var array  $pageCss   Optional: ['/css/extra.css'] */
/** @var array  $pageJs    Optional: ['/js/extra.js']   */
$pageTitle = $pageTitle ?? 'CIS';
$pageCss   = $pageCss   ?? [];
$pageJs    = $pageJs    ?? [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <meta name="robots" content="noindex,nofollow">
  <title><?= safe($pageTitle) ?> · The Vape Shed</title>

  <!-- Core CSS (align versions!) -->
  <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
  <link rel="dns-prefetch" href="//cdn.jsdelivr.net">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/pace/1.2.4/themes/blue/pace-theme-minimal.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/simple-line-icons/2.5.5/css/simple-line-icons.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">

  <!-- Your app styles -->
  <link rel="stylesheet" href="<?= asset('/assets/css/style1.css') ?>">
  <link rel="stylesheet" href="<?= asset('/assets/css/custom.css') ?>">

  <?php foreach ($pageCss as $href): ?>
    <link rel="stylesheet" href="<?= asset($href) ?>">
  <?php endforeach; ?>

  <script>
    window.CIS = { CSRF: "<?= safe(csrf_token()) ?>", ENV: "<?= safe(APP_ENV) ?>" };
  </script>
</head>

<body class="app header-fixed sidebar-fixed aside-menu-fixed sidebar-lg-show">
  <!-- Header -->
  <header class="app-header navbar">
    <button class="navbar-toggler sidebar-toggler d-lg-none mr-auto" type="button" data-toggle="sidebar-show" aria-label="Toggle sidebar">
      <span class="navbar-toggler-icon"></span>
    </button>

    <a class="navbar-brand" href="/">
      <img class="navbar-brand-full" src="/assets/img/brand/logo.jpg" width="120" height="38" alt="The Vape Shed">
      <img class="navbar-brand-minimized" src="/assets/img/brand/vapeshed-emblem.png" width="30" height="30" alt="VS">
    </a>

    <ul class="nav navbar-nav ml-auto">
      <li class="nav-item d-md-down-none align-self-center mr-3"></li>
      <li class="nav-item d-md-down-none">
        <span>Hello, <?= safe($_SESSION['user_name'] ?? 'User') ?> <a class="nav-link d-inline" href="/?logout=1">Logout</a></span>
      </li>
      <li class="nav-item dropdown">
        <a class="nav-link dropdown-toggle" id="notificationToggle" data-toggle="dropdown" role="button" aria-haspopup="true" aria-expanded="false">
          <div class="notification-bell" style="background:#eee;width:35px;height:35px;border-radius:50px;margin:0 5px 0 15px;padding:0;cursor:pointer;">
            <span class="badge badge-pill badge-danger notific-count" style="margin-top:-20px;margin-left:10px;display:none;"><span class="userNotifCounter">0</span></span>
            <i class="fa fa-bell-o" style="padding-top:10px;font-size:15px;"></i>
          </div>
        </a>
        <div id="notificationDropDown" class="dropdown-menu dropdown-menu-right dropdown-menu-lg pt-0" aria-label="Notifications" role="menu">
          <h6 class="dropdown-header bg-light mb-0"><strong>You have <span class="userNotifCounter">0</span> messages</strong></h6>
          <div class="dropdown-divider"></div>
          <a class="dropdown-item text-center" href="/notification-history.php" role="menuitem"><strong>View all messages</strong></a>
        </div>
      </li>
      <li class="nav-item d-md-down-none">
        <img class="img-avatar" src="/assets/img/avatars/6.jpg" alt="Avatar">
      </li>
    </ul>
  </header>

  <div class="app-body">
    <!-- Sidebar -->
    <div class="sidebar">
      <nav class="sidebar-nav">
        <ul class="nav">
          <li class="nav-item"><a class="nav-link" href="/index.php">View Dashboard</a></li>
          <!-- … add your stable nav items here … -->
        </ul>
      </nav>
    </div>

    <!-- Main -->
    <main class="main" id="main">
      <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-2">
          <li class="breadcrumb-item"><a href="/">Home</a></li>
          <li class="breadcrumb-item active" aria-current="page"><?= safe($pageTitle) ?></li>
        </ol>
      </nav>

      <div class="container-fluid">
        <?= $content ?? '' ?>
      </div>
    </main>
  </div>

  <!-- Footer -->
  <footer class="app-footer">
    <div>
      <a href="https://www.vapeshed.co.nz">The Vape Shed</a> <span>&copy; <?= date('Y') ?> Ecigdis Ltd</span>
    </div>
    <div class="ml-auto">
      <small>Developed by <a href="https://www.pearcestephens.co.nz" target="_blank" rel="noopener">Pearce Stephens</a></small>
      <a href="/submit_ticket.php" class="btn btn-sm btn-outline-danger ml-2">🐞 Report a Bug</a>
    </div>
  </footer>

  <!-- Core JS (load once; consistent versions) -->
  <script src="https://code.jquery.com/jquery-3.7.1.min.js" crossorigin="anonymous"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/pace/1.2.4/pace.min.js" defer></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/perfect-scrollbar/1.5.5/perfect-scrollbar.min.js" defer></script>
  <script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.1/dist/umd/popper.min.js" defer></script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.min.js" defer></script>
  <script src="https://cdn.jsdelivr.net/npm/@coreui/coreui@3.4.0/dist/js/coreui.bundle.min.js" defer></script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@2.9.4/dist/Chart.min.js" defer></script>

  <!-- Your app JS -->
  <script src="<?= asset('/assets/js/main.js') ?>" defer></script>

  <?php foreach ($pageJs as $src): ?>
    <script src="<?= asset($src) ?>" defer></script>
  <?php endforeach; ?>
</body>
</html>
