<?php
// Existing code...

// Process campaign update
if ($_POST && isset($_POST['action']) && $_POST['action'] == 'update_campaign') {
    // Existing fields...
    $endpoint_url = $_POST['endpoint_url'] ?? '';
    
    // Update statement
    $stmt = $pdo->prepare("
        UPDATE campaigns
        SET 
            name = ?,
            status = ?,
            type = ?,
            daily_budget = ?,
            total_budget = ?,
            start_date = ?,
            end_date = ?,
            endpoint_url = ?
        WHERE id = ?
    ");
    
    $stmt->execute([
        $name,
        $status,
        $type,
        $daily_budget,
        $total_budget,
        $start_date,
        $end_date,
        $endpoint_url,
        $campaign_id
    ]);
    
    // Success message...
}

// Rest of code...
?>

<!-- Form field for RTB endpoint -->
<div class="mb-3 rtb-field" style="display: <?php echo $campaign['type'] == 'rtb' ? 'block' : 'none'; ?>;">
    <label for="endpoint_url" class="form-label">RTB Endpoint URL</label>
    <div class="input-group">
        <span class="input-group-text"><i class="fas fa-link"></i></span>
        <input type="url" class="form-control" id="endpoint_url" name="endpoint_url"
               value="<?php echo htmlspecialchars($campaign['endpoint_url'] ?? ''); ?>"
               placeholder="http://rtb.exoclick.com/rtb.php?idzone=5128252&fid=e573a1c2a656509b0112f7213359757be76929c7">
    </div>
    <div class="form-text">Input full ExoClick RTB endpoint URL including idzone and fid parameters</div>
</div>

<script>
// Show RTB fields only when RTB type is selected
document.addEventListener('DOMContentLoaded', function() {
    const typeSelector = document.getElementById('campaign_type');
    const rtbFields = document.querySelectorAll('.rtb-field');
    
    if (typeSelector) {
        typeSelector.addEventListener('change', function() {
            const isRTB = this.value === 'rtb';
            rtbFields.forEach(field => {
                field.style.display = isRTB ? 'block' : 'none';
            });
        });
    }
});
</script>