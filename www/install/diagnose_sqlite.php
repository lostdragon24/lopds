<?php
// /install/diagnose_sqlite.php

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../init.php';

$path = $_GET['path'] ?? Config::getDbPath();
$results = [];

function checkPath($path, $level = 0)
{
    $result = [
        'path' => $path,
        'exists' => file_exists($path),
        'readable' => is_readable($path),
        'writable' => is_writable($path),
        'executable' => is_executable($path),
        'perms' => file_exists($path) ? substr(sprintf('%o', fileperms($path)), -4) : 'N/A',
        'owner' => file_exists($path) && function_exists('posix_getpwuid') ? posix_getpwuid(fileowner($path))['name'] : 'N/A',
        'group' => file_exists($path) && function_exists('posix_getgrgid') ? posix_getgrgid(filegroup($path))['name'] : 'N/A'
    ];

    if (is_dir($path) && $level < 10) {
        $result['children'] = [];
        $parent = dirname($path);
        if ($parent != $path) {
            $result['children'][] = checkPath($parent, $level + 1);
        }
    }

    return $result;
}

$results['target'] = checkPath($path);
$results['current_user'] = get_current_user();
$results['php_user'] = function_exists('posix_geteuid') ? posix_getpwuid(posix_geteuid())['name'] : 'N/A';
$results['sapi'] = php_sapi_name();

require_once __DIR__ . '/templates/header.php';
?>

<div class="container mt-4">
    <h1 class="mb-4">
        <i class="fas fa-stethoscope me-2"></i>
        <?php echo __('diagnose_title'); ?>
    </h1>
    
    <div class="card mb-4">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0">
                <i class="fas fa-search me-2"></i>
                <?php echo __('diagnose_checking_path'); ?>: <?php echo htmlspecialchars($path); ?>
            </h5>
        </div>
        <div class="card-body">
            <table class="table table-bordered">
                <tr>
                    <th><?php echo __('diagnose_parameter'); ?></th>
                    <th><?php echo __('diagnose_value'); ?></th>
                    <th><?php echo __('diagnose_status'); ?></th>
                </tr>
                <tr>
                    <td><?php echo __('diagnose_current_user'); ?></td>
                    <td><?php echo $results['current_user']; ?></td>
                    <td>
                        <?php if ($results['current_user'] === 'www-data' || $results['current_user'] === 'apache'): ?>
                            <span class="badge bg-warning"><?php echo __('diagnose_webserver'); ?></span>
                        <?php else: ?>
                            <span class="badge bg-info"><?php echo $results['current_user']; ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <td><?php echo __('diagnose_effective_user'); ?></td>
                    <td><?php echo $results['php_user']; ?></td>
                    <td>
                        <?php if ($results['php_user'] === 'root'): ?>
                            <span class="badge bg-danger">⚠️ <?php echo __('diagnose_root_warning'); ?></span>
                        <?php else: ?>
                            <span class="badge bg-secondary"><?php echo $results['php_user']; ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <td><?php echo __('diagnose_sapi'); ?></td>
                    <td><?php echo $results['sapi']; ?></td>
                    <td>
                        <?php if ($results['sapi'] === 'cli'): ?>
                            <span class="badge bg-success"><?php echo __('diagnose_command_line'); ?></span>
                        <?php else: ?>
                            <span class="badge bg-primary"><?php echo __('diagnose_web'); ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>
        </div>
    </div>
    
    <div class="card">
        <div class="card-header bg-info text-white">
            <h5 class="mb-0">
                <i class="fas fa-folder-tree me-2"></i>
                <?php echo __('diagnose_path_check'); ?>
            </h5>
        </div>
        <div class="card-body">
            <?php echo displayPathCheck($results['target']); ?>
        </div>
    </div>
    
    <div class="mt-4">
        <h5><?php echo __('diagnose_solutions'); ?></h5>
        <ul class="list-group">
            <li class="list-group-item">
                <strong><?php echo __('diagnose_solution_1'); ?></strong>
                <pre class="bg-light p-2 mt-2"><code>sudo chown -R www-data:www-data <?php echo dirname($path); ?>
sudo chmod -R 755 <?php echo dirname($path); ?></code></pre>
            </li>
            <li class="list-group-item">
                <strong><?php echo __('diagnose_solution_2'); ?></strong>
                <pre class="bg-light p-2 mt-2"><code>sudo semanage fcontext -a -t httpd_sys_rw_content_t "<?php echo dirname($path); ?>(/.*)?"
sudo restorecon -Rv <?php echo dirname($path); ?></code></pre>
            </li>
            <li class="list-group-item">
                <strong><?php echo __('diagnose_solution_3'); ?></strong>
                <pre class="bg-light p-2 mt-2"><code>/home/<?php echo get_current_user(); ?>/library.db</code></pre>
            </li>
        </ul>
    </div>
    
    <div class="mt-4">
        <a href="index.php?step=2&type=sqlite" class="btn btn-primary">
            <i class="fas fa-arrow-left me-2"></i>
            <?php echo __('diagnose_back_to_install'); ?>
        </a>
    </div>
</div>

<?php
function displayPathCheck($item, $level = 0)
{
    $indent = str_repeat('    ', $level);
    $html = "<div style='margin-left: {$level}em'>";

    $icon = $item['exists'] ? '✅' : '❌';
    $type = is_dir($item['path']) ? '📁' : '📄';

    $html .= "<div class='p-2 " . ($item['exists'] ? 'bg-light' : 'bg-danger bg-opacity-10') . " rounded mb-1'>";
    $html .= "$indent $icon $type <code>" . basename($item['path']) . "</code>";

    if ($item['exists']) {
        $html .= " <span class='badge bg-secondary'><?php echo __('diagnose_permissions'); ?>: {$item['perms']}</span>";
        $html .= " <span class='badge bg-info'><?php echo __('diagnose_owner'); ?>: {$item['owner']}</span>";
        $html .= " <span class='badge bg-info'><?php echo __('diagnose_group'); ?>: {$item['group']}</span>";

        if (!is_dir($item['path'])) {
            $html .= " " . ($item['readable'] ? '🔵 ' . __('diagnose_readable') : '⚫ ' . __('diagnose_not_readable'));
            $html .= " " . ($item['writable'] ? '🟢 ' . __('diagnose_writable') : '🔴 ' . __('diagnose_not_writable'));
        } else {
            $html .= " " . ($item['executable'] ? '🟢 ' . __('diagnose_accessible') : '🔴 ' . __('diagnose_not_accessible'));
        }
    }

    $html .= "</div>";

    if (isset($item['children'])) {
        foreach ($item['children'] as $child) {
            $html .= displayPathCheck($child, $level + 1);
        }
    }

    $html .= "</div>";
    return $html;
}

require_once __DIR__ . '/templates/footer.php';
?>