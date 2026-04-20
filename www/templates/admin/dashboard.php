<h1 class="mb-4">
    <i class="fas fa-tachometer-alt me-2"></i>
    <?php echo __('dashboard'); ?>
</h1>

<?php if (isset($_SESSION['admin_user'])) { ?>
    <div class="alert alert-success">
        <i class="fas fa-check-circle me-2"></i>
        <?php echo sprintf(__('admin_welcome'), htmlspecialchars($_SESSION['admin_user'])); ?>
    </div>
<?php } ?>

<div class="row">
    <?php foreach ($stats as $stat) { ?>
    <div class="col-xl-3 col-md-6 mb-4">
        <div class="card border-left-<?php echo $stat['color'] ?? 'primary'; ?> shadow h-100 py-2">
            <div class="card-body">
                <div class="row no-gutters align-items-center">
                    <div class="col mr-2">
                        <div class="text-xs font-weight-bold text-<?php echo $stat['color'] ?? 'primary'; ?> text-uppercase mb-1">
                            <?php echo $stat['label']; ?>
                        </div>
                        <div class="h5 mb-0 font-weight-bold text-gray-800">
                            <?php echo $stat['value']; ?>
                        </div>
                    </div>
                    <div class="col-auto">
                        <i class="fas <?php echo $stat['icon']; ?> fa-2x text-gray-300"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php } ?>
</div>

<div class="row">
    <div class="col-md-6">
        <div class="card shadow mb-4">
            <div class="card-header py-3 bg-dark text-white">
                <h6 class="m-0 font-weight-bold">
                    <i class="fas fa-server me-2"></i><?php echo __('dashboard_system_info'); ?>
                </h6>
            </div>
            <div class="card-body">
                <table class="table table-sm">
                    <?php foreach ($system as $key => $value) { ?>
                    <tr>
                        <th style="width: 40%"><?php echo $key; ?>:</th>
                        <td><?php echo $value; ?></td>
                    </tr>
                    <?php } ?>
                </table>
            </div>
        </div>
    </div>
    
    <div class="col-md-6">
        <div class="card shadow mb-4">
            <div class="card-header py-3 bg-dark text-white">
                <h6 class="m-0 font-weight-bold">
                    <i class="fas fa-book me-2"></i><?php echo __('dashboard_recent_books'); ?>
                </h6>
            </div>
            <div class="card-body">
                <?php if (empty($recent_books)) { ?>
                    <p class="text-muted text-center py-4">
                        <i class="fas fa-book-open fa-2x mb-2"></i><br>
                        <?php echo __('dashboard_no_books'); ?>
                    </p>
                <?php } else { ?>
                    <div class="list-group">
                        <?php foreach ($recent_books as $book) { ?>
                        <div class="list-group-item list-group-item-action">
                            <div class="d-flex w-100 justify-content-between">
                                <h6 class="mb-1"><?php echo htmlspecialchars($book['title'] ?: __('book_untitled')); ?></h6>
                                <small class="text-muted"><?php echo date('d.m.Y', strtotime($book['added_date'])); ?></small>
                            </div>
                            <small class="text-muted">
                                <?php echo htmlspecialchars($book['author'] ?: __('book_unknown_author')); ?>
                            </small>
                        </div>
                        <?php } ?>
                    </div>
                <?php } ?>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-6">
        <div class="card shadow mb-4">
            <div class="card-header py-3 bg-dark text-white">
                <h6 class="m-0 font-weight-bold">
                    <i class="fas fa-robot me-2"></i><?php echo __('dashboard_scanner_status'); ?>
                </h6>
            </div>
            <div class="card-body">
                <table class="table table-sm">
                    <tr>
                        <th><?php echo __('admin_scanner_available'); ?></th>
                        <td>
                            <span class="badge <?php echo $scanner_status['available'] ? 'bg-success' : 'bg-danger'; ?>">
                                <?php echo $scanner_status['available'] ? __('admin_scanner_yes') : __('admin_scanner_no'); ?>
                            </span>
                        </td>
                    </tr>
                    <tr>
                        <th><?php echo __('admin_scanner_path'); ?></th>
                        <td><small><?php echo $scanner_status['scanner_path']; ?></small></td>
                    </tr>
                </table>
                <a href="?action=scanner" class="btn btn-primary btn-sm">
                    <i class="fas fa-cog me-2"></i><?php echo __('admin_scanner_control'); ?>
                </a>
            </div>
        </div>
    </div>
    
    <div class="col-md-6">
        <div class="card shadow mb-4">
            <div class="card-header py-3 bg-dark text-white">
                <h6 class="m-0 font-weight-bold">
                    <i class="fas fa-bolt me-2"></i><?php echo __('dashboard_cache_stats'); ?>
                </h6>
            </div>
            <div class="card-body">
                <?php if (!empty($cache_stats['apcu'])) { ?>
                    <table class="table table-sm">
                        <tr>
                            <th><?php echo __('admin_cache_hits'); ?></th>
                            <td><?php echo number_format($cache_stats['apcu']['hits']); ?></td>
                        </tr>
                        <tr>
                            <th><?php echo __('admin_cache_misses'); ?></th>
                            <td><?php echo number_format($cache_stats['apcu']['misses']); ?></td>
                        </tr>
                        <tr>
                            <th><?php echo __('admin_cache_efficiency'); ?></th>
                            <td><?php echo $cache_stats['apcu']['effectiveness']; ?>%</td>
                        </tr>
                    </table>
                <?php } else { ?>
                    <p class="text-muted text-center py-4">
                        <i class="fas fa-ban fa-2x mb-2"></i><br>
                        <?php echo __('admin_cache_not_used'); ?>
                    </p>
                <?php } ?>
            </div>
        </div>
    </div>
</div>