<div class="card panel panel-default">
    <div class="card-body panel-body text-center">
        <h4>Service Management</h4>

        <div style="margin-bottom: 15px;">
            <button id="tcadmin3-sso" class="btn btn-primary"><i class="fas fa-sign-in-alt"></i> Login to Control Panel</button>
        </div>

        <div id="tcadmin3-status-container" style="margin-bottom: 15px;">
            Status: <span id="tcadmin3-status" class="label label-default">Loading...</span>
        </div>

        <div id="tcadmin3-actions">
            <button class="btn btn-success action-btn" data-action="Start" style="display:none;"><i class="fas fa-play"></i> Start</button>
            <button class="btn btn-warning action-btn" data-action="Restart" style="display:none;"><i class="fas fa-sync"></i> Restart</button>
            <button class="btn btn-danger action-btn" data-action="Stop" style="display:none;"><i class="fas fa-stop"></i> Stop</button>
            <button class="btn btn-danger action-btn" data-action="Kill" style="display:none;"><i class="fas fa-skull"></i> Force Kill</button>
        </div>

        <div id="tcadmin3-message" style="margin-top: 15px; display:none;" class="alert"></div>
    </div>
</div>

<script>
    let actionStartTime = null;
    const killTimeout = null;

    function updateStatus() {
        jQuery.post('clientarea.php?action=productdetails', {
            id: '{$serviceid}',
            ajaxAction: 'getStatus'
        }, function(data) {
            if (data.success) {
                let statusText = data.status;
                let statusLower = statusText.toLowerCase();

                let labelClass = 'label-default';
                if (statusLower === 'started') labelClass = 'label-success';
                else if (statusLower === 'stopped') labelClass = 'label-danger';
                else if (statusLower.indexOf('progress') !== -1) labelClass = 'label-info';

                jQuery('#tcadmin3-status')
                    .text(statusText)
                    .attr('class', 'label ' + labelClass);

                // Button Visibility Logic
                jQuery('.action-btn').hide();
                if (statusLower === 'stopped') {
                    jQuery('.action-btn[data-action="Start"]').show();
                    clearKillLogic();
                } else if (statusLower === 'started') {
                    jQuery('.action-btn[data-action="Stop"], .action-btn[data-action="Restart"]').show();
                    clearKillLogic();
                } else {
                    // In transition (Starting, Stopping, etc.)
                    // Check if we should show Kill button
                    checkKillButton();
                }
            }
        });
    }

    function checkKillButton() {
        if (actionStartTime && (new Date().getTime() - actionStartTime) > 30000) {
            jQuery('.action-btn[data-action="Kill"]').show();
        }
    }

    function clearKillLogic() {
        actionStartTime = null;
    }

    jQuery(document).ready(function() {
        updateStatus();
        setInterval(updateStatus, 5000); // Check status every 5 seconds

        jQuery('.action-btn').click(function() {
            let btn = jQuery(this);
            let action = btn.data('action');

            if (action === 'Stop' || action === 'Restart') {
                actionStartTime = new Date().getTime();
            }

            btn.prop('disabled', true).addClass('disabled');
            jQuery('#tcadmin3-message').hide();

            jQuery.post('clientarea.php?action=productdetails', {
                id: '{$serviceid}',
                ajaxAction: 'performAction',
                serviceAction: action
            }, function(data) {
                btn.prop('disabled', false).removeClass('disabled');
                if (data.success) {
                    jQuery('#tcadmin3-message')
                        .text('Action ' + action + ' initiated successfully.')
                        .attr('class', 'alert alert-success')
                        .show();
                    updateStatus();
                } else {
                    jQuery('#tcadmin3-message')
                        .text('Error: ' + data.error)
                        .attr('class', 'alert alert-danger')
                        .show();
                }
            });
        });

        jQuery('#tcadmin3-sso').click(function() {
            let btn = jQuery(this);
            btn.prop('disabled', true).addClass('disabled');
            jQuery('#tcadmin3-message').hide();

            // Open the tab inside the click so pop-up blockers allow it.
            let panelWindow = window.open('', '_blank');

            jQuery.post('clientarea.php?action=productdetails', {
                id: '{$serviceid}',
                ajaxAction: 'singleSignOn'
            }, function(data) {
                btn.prop('disabled', false).removeClass('disabled');
                if (data.success && data.redirectTo) {
                    if (panelWindow) {
                        panelWindow.location = data.redirectTo;
                    } else {
                        window.location = data.redirectTo; // pop-up blocked: use current tab
                    }
                } else {
                    if (panelWindow) panelWindow.close();
                    jQuery('#tcadmin3-message')
                        .text('Error: ' + (data.errorMsg || 'Could not sign in to the control panel.'))
                        .attr('class', 'alert alert-danger')
                        .show();
                }
            }).fail(function() {
                if (panelWindow) panelWindow.close();
                btn.prop('disabled', false).removeClass('disabled');
                jQuery('#tcadmin3-message')
                    .text('Error: could not reach the server. Please try again.')
                    .attr('class', 'alert alert-danger')
                    .show();
            });
        });
    });
</script>
