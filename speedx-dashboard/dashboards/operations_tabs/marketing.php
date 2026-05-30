<div class="tab-content" id="marketing">
    <div class="card">
        <h2>📢 Active Campaigns</h2>
        <table>
            <thead><tr><th>Campaign</th><th>Type</th><th>Budget</th><th>Spent</th><th>Orders</th><th>New Customers</th><th>ROI</th><th>Status</th></tr></thead>
            <tbody>
                <?php
                $campaigns = $pdo->query("SELECT * FROM operations_marketing ORDER BY start_date DESC")->fetchAll();
                foreach ($campaigns as $c):
                    $status_class = $c['status'] == 'active' ? 'badge-green' : ($c['status'] == 'paused' ? 'badge-yellow' : 'badge-blue');
                ?>
                <tr>
                    <td><strong><?php echo $c['campaign_name']; ?></strong></td>
                    <td><?php echo ucfirst(str_replace('_', ' ', $c['campaign_type'])); ?></td>
                    <td>₹<?php echo number_format($c['budget']); ?></td>
                    <td>₹<?php echo number_format($c['spend_so_far']); ?></td>
                    <td><?php echo $c['orders_generated']; ?></td>
                    <td><?php echo $c['new_customers']; ?></td>
                    <td style="color:#48bb78;"><?php echo $c['roi_percent']; ?>%</td>
                    <td><span class="badge <?php echo $status_class; ?>"><?php echo ucfirst($c['status']); ?></span></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>