<?php
/**
 * Admin Dashboard Template
 *
 * @package TrackSubmissionForm
 * @since 4.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

$dashboard = new TSF_Dashboard();
$period = isset($_GET['period']) ? sanitize_text_field($_GET['period']) : '30days';
$stats = $dashboard->get_stats($period);
$approval_rate = $dashboard->get_approval_rate();

?>

<div class="wrap tsf-dashboard">
    <h1><?php _e('Track Submissions Dashboard', 'tsf'); ?></h1>

    <div class="tsf-period-selector">
        <a href="?page=tsf-dashboard&period=7days" class="button <?php echo $period === '7days' ? 'button-primary' : ''; ?>">
            <?php _e('7 Days', 'tsf'); ?>
        </a>
        <a href="?page=tsf-dashboard&period=30days" class="button <?php echo $period === '30days' ? 'button-primary' : ''; ?>">
            <?php _e('30 Days', 'tsf'); ?>
        </a>
        <a href="?page=tsf-dashboard&period=90days" class="button <?php echo $period === '90days' ? 'button-primary' : ''; ?>">
            <?php _e('90 Days', 'tsf'); ?>
        </a>
        <a href="?page=tsf-dashboard&period=year" class="button <?php echo $period === 'year' ? 'button-primary' : ''; ?>">
            <?php _e('1 Year', 'tsf'); ?>
        </a>
    </div>

    <!-- Stats Cards -->
    <div class="tsf-stats-grid">
        <div class="tsf-stat-card">
            <div class="tsf-stat-icon">📊</div>
            <div class="tsf-stat-content">
                <div class="tsf-stat-value"><?php echo number_format($stats['total']); ?></div>
                <div class="tsf-stat-label"><?php _e('Total Submissions', 'tsf'); ?></div>
            </div>
        </div>

        <div class="tsf-stat-card">
            <div class="tsf-stat-icon">✅</div>
            <div class="tsf-stat-content">
                <div class="tsf-stat-value"><?php echo number_format($stats['status_counts']['approved']); ?></div>
                <div class="tsf-stat-label"><?php _e('Approved', 'tsf'); ?></div>
            </div>
        </div>

        <div class="tsf-stat-card">
            <div class="tsf-stat-icon">⏳</div>
            <div class="tsf-stat-content">
                <div class="tsf-stat-value"><?php echo number_format($stats['status_counts']['pending']); ?></div>
                <div class="tsf-stat-label"><?php _e('Pending Review', 'tsf'); ?></div>
            </div>
        </div>

        <div class="tsf-stat-card">
            <div class="tsf-stat-icon">📈</div>
            <div class="tsf-stat-content">
                <div class="tsf-stat-value"><?php echo $approval_rate; ?>%</div>
                <div class="tsf-stat-label"><?php _e('Approval Rate', 'tsf'); ?></div>
            </div>
        </div>
    </div>

    <!-- Charts Row -->
    <div class="tsf-charts-row">
        <!-- Timeline Chart -->
        <div class="tsf-chart-container">
            <h2><?php _e('Submissions Over Time', 'tsf'); ?></h2>
            <div class="tsf-chart" id="tsf-timeline-chart">
                <?php if (!empty($stats['timeline'])): ?>
                    <div class="tsf-simple-chart">
                        <?php
                        $max_count = max(array_column($stats['timeline'], 'count'));
                        foreach ($stats['timeline'] as $data):
                            $height = $max_count > 0 ? ($data->count / $max_count) * 100 : 0;
                            ?>
                            <div class="tsf-chart-bar" title="<?php echo esc_attr($data->date . ': ' . $data->count); ?>">
                                <div class="tsf-chart-bar-fill" style="height: <?php echo $height; ?>%;"></div>
                                <div class="tsf-chart-bar-label"><?php echo date('M j', strtotime($data->date)); ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p><?php _e('No data available', 'tsf'); ?></p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Status Distribution -->
        <div class="tsf-chart-container">
            <h2><?php _e('Status Distribution', 'tsf'); ?></h2>
            <div class="tsf-status-list">
                <?php
                $statuses = [
                    'pending' => ['label' => __('Pending', 'tsf'), 'color' => '#f0b849'],
                    'approved' => ['label' => __('Approved', 'tsf'), 'color' => '#46b450'],
                    'rejected' => ['label' => __('Rejected', 'tsf'), 'color' => '#dc3232'],
                    'draft' => ['label' => __('Draft', 'tsf'), 'color' => '#999'],
                ];

                foreach ($statuses as $status => $config):
                    $count = $stats['status_counts'][$status] ?? 0;
                    ?>
                    <div class="tsf-status-item">
                        <span class="tsf-status-color" style="background: <?php echo $config['color']; ?>;"></span>
                        <span class="tsf-status-label"><?php echo $config['label']; ?></span>
                        <span class="tsf-status-count"><?php echo $count; ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Top Lists Row -->
    <div class="tsf-charts-row">
        <!-- Top Genres -->
        <div class="tsf-chart-container">
            <h2><?php _e('Top Genres', 'tsf'); ?></h2>
            <div class="tsf-top-list">
                <?php if (!empty($stats['top_genres'])): ?>
                    <?php foreach ($stats['top_genres'] as $item): ?>
                        <div class="tsf-top-item">
                            <span class="tsf-top-label"><?php echo esc_html($item->genre); ?></span>
                            <span class="tsf-top-count"><?php echo number_format($item->count); ?></span>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p><?php _e('No data available', 'tsf'); ?></p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Top Countries -->
        <div class="tsf-chart-container">
            <h2><?php _e('Top Countries', 'tsf'); ?></h2>
            <div class="tsf-top-list">
                <?php if (!empty($stats['top_countries'])): ?>
                    <?php foreach ($stats['top_countries'] as $item): ?>
                        <div class="tsf-top-item">
                            <span class="tsf-top-label"><?php echo esc_html($item->country); ?></span>
                            <span class="tsf-top-count"><?php echo number_format($item->count); ?></span>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p><?php _e('No data available', 'tsf'); ?></p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<style>
.tsf-dashboard {
    margin: 20px;
}

.tsf-period-selector {
    margin: 20px 0;
}

.tsf-stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin: 30px 0;
}

.tsf-stat-card {
    background: white;
    border: 1px solid #ddd;
    border-radius: 8px;
    padding: 20px;
    display: flex;
    align-items: center;
    gap: 15px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.05);
}

.tsf-stat-icon {
    font-size: 32px;
}

.tsf-stat-value {
    font-size: 28px;
    font-weight: bold;
    color: #2271b1;
}

.tsf-stat-label {
    color: #666;
    font-size: 13px;
}

.tsf-charts-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
    gap: 20px;
    margin: 20px 0;
}

.tsf-chart-container {
    background: white;
    border: 1px solid #ddd;
    border-radius: 8px;
    padding: 20px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.05);
}

.tsf-chart-container h2 {
    margin-top: 0;
    font-size: 18px;
}

.tsf-simple-chart {
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    height: 200px;
    padding: 10px 0;
    gap: 4px;
}

.tsf-chart-bar {
    flex: 1;
    position: relative;
    display: flex;
    flex-direction: column;
    justify-content: flex-end;
    cursor: pointer;
}

.tsf-chart-bar-fill {
    background: linear-gradient(to top, #2271b1, #4a93d5);
    border-radius: 4px 4px 0 0;
    min-height: 2px;
    transition: opacity 0.2s;
}

.tsf-chart-bar:hover .tsf-chart-bar-fill {
    opacity: 0.8;
}

.tsf-chart-bar-label {
    position: absolute;
    bottom: -25px;
    left: 50%;
    transform: translateX(-50%) rotate(-45deg);
    font-size: 10px;
    color: #666;
    white-space: nowrap;
}

.tsf-status-list, .tsf-top-list {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.tsf-status-item, .tsf-top-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px;
    background: #f9f9f9;
    border-radius: 4px;
}

.tsf-status-color {
    width: 16px;
    height: 16px;
    border-radius: 50%;
}

.tsf-status-label, .tsf-top-label {
    flex: 1;
}

.tsf-status-count, .tsf-top-count {
    font-weight: bold;
    color: #2271b1;
}
</style>
