/**
 * RTB & RON Platform - Admin JavaScript
 * Version: 1.0.0
 * Date: 2025-06-23 20:53:07
 * Author: simoncode12
 */

(function() {
    'use strict';

    // Initialize when DOM is ready
    document.addEventListener('DOMContentLoaded', function() {
        initializeTooltips();
        initializeDataTables();
        initializeCharts();
        initializeFormValidation();
        initializeAjaxHandlers();
        initializeNotifications();
        initializeCounters();
        initializeDatePickers();
        initializeModalHandlers();
    });

    // Initialize Bootstrap tooltips
    function initializeTooltips() {
        const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        tooltipTriggerList.map(function(tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl);
        });
    }

    // Initialize DataTables for better table functionality
    function initializeDataTables() {
        if (typeof $.fn.DataTable !== 'undefined') {
            $('.datatable').DataTable({
                responsive: true,
                pageLength: 25,
                order: [[0, 'desc']],
                language: {
                    search: "_INPUT_",
                    searchPlaceholder: "Search records..."
                }
            });
        }
    }

    // Initialize Chart.js charts
    function initializeCharts() {
        // Dashboard Performance Chart
        const performanceChart = document.getElementById('performanceChart');
        if (performanceChart) {
            const ctx = performanceChart.getContext('2d');
            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: getDaysArray(7),
                    datasets: [{
                        label: 'Impressions',
                        data: performanceChart.dataset.impressions ? JSON.parse(performanceChart.dataset.impressions) : [],
                        borderColor: 'rgb(75, 192, 192)',
                        backgroundColor: 'rgba(75, 192, 192, 0.1)',
                        tension: 0.3
                    }, {
                        label: 'Clicks',
                        data: performanceChart.dataset.clicks ? JSON.parse(performanceChart.dataset.clicks) : [],
                        borderColor: 'rgb(255, 99, 132)',
                        backgroundColor: 'rgba(255, 99, 132, 0.1)',
                        tension: 0.3
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true
                        }
                    }
                }
            });
        }
    }

    // Form validation
    function initializeFormValidation() {
        const forms = document.querySelectorAll('.needs-validation');
        Array.prototype.slice.call(forms).forEach(function(form) {
            form.addEventListener('submit', function(event) {
                if (!form.checkValidity()) {
                    event.preventDefault();
                    event.stopPropagation();
                }
                form.classList.add('was-validated');
            }, false);
        });
    }

    // AJAX handlers for common operations
    function initializeAjaxHandlers() {
        // Status toggle handlers
        document.querySelectorAll('.toggle-status').forEach(function(element) {
            element.addEventListener('click', function(e) {
                e.preventDefault();
                const url = this.getAttribute('data-url');
                const id = this.getAttribute('data-id');
                const currentStatus = this.getAttribute('data-status');
                
                toggleStatus(url, id, currentStatus, this);
            });
        });

        // Delete handlers with confirmation
        document.querySelectorAll('.delete-item').forEach(function(element) {
            element.addEventListener('click', function(e) {
                e.preventDefault();
                const url = this.getAttribute('data-url');
                const id = this.getAttribute('data-id');
                const type = this.getAttribute('data-type') || 'item';
                
                confirmDelete(url, id, type);
            });
        });
    }

    // Toggle status function
    function toggleStatus(url, id, currentStatus, element) {
        const newStatus = currentStatus === 'active' ? 'inactive' : 'active';
        
        fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({
                id: id,
                status: newStatus
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Update UI
                element.setAttribute('data-status', newStatus);
                const badge = element.querySelector('.badge') || element;
                badge.classList.remove('bg-success', 'bg-secondary');
                badge.classList.add(newStatus === 'active' ? 'bg-success' : 'bg-secondary');
                badge.textContent = newStatus.charAt(0).toUpperCase() + newStatus.slice(1);
                
                showNotification('Status updated successfully!', 'success');
            } else {
                showNotification('Error updating status: ' + (data.message || 'Unknown error'), 'error');
            }
        })
        .catch(error => {
            showNotification('Network error: ' + error.message, 'error');
        });
    }

    // Delete confirmation
    function confirmDelete(url, id, type) {
        Swal.fire({
            title: 'Are you sure?',
            text: `You are about to delete this ${type}. This action cannot be undone!`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6',
            confirmButtonText: 'Yes, delete it!'
        }).then((result) => {
            if (result.isConfirmed) {
                performDelete(url, id);
            }
        });
    }

    // Perform delete operation
    function performDelete(url, id) {
        fetch(url, {
            method: 'DELETE',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({ id: id })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Remove row from table
                const row = document.querySelector(`tr[data-id="${id}"]`);
                if (row) {
                    row.style.transition = 'opacity 0.5s';
                    row.style.opacity = '0';
                    setTimeout(() => row.remove(), 500);
                }
                
                showNotification('Item deleted successfully!', 'success');
            } else {
                showNotification('Error deleting item: ' + (data.message || 'Unknown error'), 'error');
            }
        })
        .catch(error => {
            showNotification('Network error: ' + error.message, 'error');
        });
    }

    // Show notifications
    function showNotification(message, type = 'info') {
        // Using SweetAlert2 for better notifications
        if (typeof Swal !== 'undefined') {
            const Toast = Swal.mixin({
                toast: true,
                position: 'top-end',
                showConfirmButton: false,
                timer: 3000,
                timerProgressBar: true,
                didOpen: (toast) => {
                    toast.addEventListener('mouseenter', Swal.stopTimer);
                    toast.addEventListener('mouseleave', Swal.resumeTimer);
                }
            });

            Toast.fire({
                icon: type === 'error' ? 'error' : type,
                title: message
            });
        } else {
            // Fallback to basic alert
            alert(message);
        }
    }

    // Initialize real-time counters
    function initializeCounters() {
        // Update impressions counter every 5 seconds
        setInterval(updateLiveStats, 5000);
    }

    // Update live statistics
    function updateLiveStats() {
        const liveStatsElements = document.querySelectorAll('.live-stat');
        if (liveStatsElements.length === 0) return;

        fetch('/api/live-stats.php')
            .then(response => response.json())
            .then(data => {
                liveStatsElements.forEach(element => {
                    const stat = element.getAttribute('data-stat');
                    if (data[stat] !== undefined) {
                        animateValue(element, parseInt(element.textContent.replace(/,/g, '')), data[stat], 1000);
                    }
                });
            })
            .catch(error => console.error('Error fetching live stats:', error));
    }

    // Animate number changes
    function animateValue(element, start, end, duration) {
        const range = end - start;
        const increment = range / (duration / 16);
        let current = start;
        
        const timer = setInterval(function() {
            current += increment;
            if ((increment > 0 && current >= end) || (increment < 0 && current <= end)) {
                current = end;
                clearInterval(timer);
            }
            element.textContent = formatNumber(Math.floor(current));
        }, 16);
    }

    // Format numbers with commas
    function formatNumber(num) {
        return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ",");
    }

    // Initialize date pickers
    function initializeDatePickers() {
        // Set default dates for date inputs
        const dateInputs = document.querySelectorAll('input[type="date"]');
        dateInputs.forEach(input => {
            if (!input.value && input.hasAttribute('data-default')) {
                const defaultValue = input.getAttribute('data-default');
                if (defaultValue === 'today') {
                    input.value = new Date().toISOString().split('T')[0];
                } else if (defaultValue === 'tomorrow') {
                    const tomorrow = new Date();
                    tomorrow.setDate(tomorrow.getDate() + 1);
                    input.value = tomorrow.toISOString().split('T')[0];
                }
            }
        });
    }

    // Initialize modal handlers
    function initializeModalHandlers() {
        // Creative preview modal
        const previewModal = document.getElementById('creativePreviewModal');
        if (previewModal) {
            previewModal.addEventListener('show.bs.modal', function(event) {
                const button = event.relatedTarget;
                const creative = {
                    type: button.getAttribute('data-type'),
                    content: button.getAttribute('data-content'),
                    url: button.getAttribute('data-url'),
                    width: button.getAttribute('data-width'),
                    height: button.getAttribute('data-height')
                };
                
                updateCreativePreview(creative);
            });
        }
    }

    // Update creative preview
    function updateCreativePreview(creative) {
        const previewContainer = document.getElementById('creativePreviewContent');
        if (!previewContainer) return;

        let content = '';
        switch(creative.type) {
            case 'image':
                content = `<img src="${creative.url}" class="img-fluid" alt="Creative Preview">`;
                break;
            case 'html5':
                content = `<div style="width:${creative.width}px;height:${creative.height}px;border:1px solid #ddd;">${creative.content}</div>`;
                break;
            case 'video':
                content = `<video width="${creative.width}" height="${creative.height}" controls>
                          <source src="${creative.url}" type="video/mp4">
                          Your browser does not support the video tag.
                          </video>`;
                break;
            default:
                content = '<p>Preview not available for this creative type.</p>';
        }
        
        previewContainer.innerHTML = content;
    }

    // Utility function to get days array
    function getDaysArray(days) {
        const arr = [];
        for (let i = days - 1; i >= 0; i--) {
            const d = new Date();
            d.setDate(d.getDate() - i);
            arr.push(d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' }));
        }
        return arr;
    }

    // Export functions to global scope if needed
    window.adminUtils = {
        showNotification: showNotification,
        confirmDelete: confirmDelete,
        formatNumber: formatNumber,
        updateLiveStats: updateLiveStats
    };

    // Handle sidebar state persistence
    const sidebar = document.getElementById('sidebar');
    if (sidebar) {
        // Check localStorage for sidebar state
        const sidebarState = localStorage.getItem('sidebarState');
        if (sidebarState === 'open' && window.innerWidth >= 768) {
            sidebar.style.transform = 'translateX(0px)';
        }

        // Save sidebar state on toggle
        window.addEventListener('sidebarToggled', function() {
            const isOpen = sidebar.style.transform === 'translateX(0px)';
            localStorage.setItem('sidebarState', isOpen ? 'open' : 'closed');
        });
    }

    // Copy to clipboard functionality
    document.querySelectorAll('.copy-to-clipboard').forEach(function(element) {
        element.addEventListener('click', function() {
            const text = this.getAttribute('data-copy') || this.textContent;
            navigator.clipboard.writeText(text).then(function() {
                showNotification('Copied to clipboard!', 'success');
            }).catch(function(err) {
                showNotification('Failed to copy: ' + err, 'error');
            });
        });
    });

    // Auto-refresh functionality for dashboard
    if (document.body.classList.contains('dashboard-page')) {
        const autoRefreshInterval = 60000; // 1 minute
        setInterval(function() {
            if (document.visibilityState === 'visible') {
                updateLiveStats();
                // Refresh charts if needed
                if (window.dashboardCharts) {
                    window.dashboardCharts.forEach(chart => chart.update());
                }
            }
        }, autoRefreshInterval);
    }

})();