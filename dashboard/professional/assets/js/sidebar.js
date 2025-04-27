document.addEventListener('DOMContentLoaded', function() {
    const sidebarToggle = document.getElementById('sidebar-toggle');
    const sidebar = document.getElementById('sidebar');
    const mainContent = document.getElementById('main-content');
    
    // Check for stored sidebar state
    const sidebarCollapsed = localStorage.getItem('sidebarCollapsed') === 'true';
    
    // Initialize sidebar state based on stored preference
    if (sidebarCollapsed) {
        sidebar.classList.add('collapsed');
        mainContent.classList.add('expanded');
    }
    
    // Toggle sidebar on menu button click
    sidebarToggle.addEventListener('click', function() {
        sidebar.classList.toggle('collapsed');
        mainContent.classList.toggle('expanded');
        
        // Store sidebar state in localStorage
        localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
    });
    
    // On small screens, show sidebar on hover and hide when mouse leaves
    if (window.innerWidth <= 768) {
        sidebar.addEventListener('mouseenter', function() {
            if (sidebar.classList.contains('collapsed')) {
                sidebar.classList.add('show');
            }
        });
        
        sidebar.addEventListener('mouseleave', function() {
            if (sidebar.classList.contains('collapsed')) {
                sidebar.classList.remove('show');
            }
        });
    }
            // User dropdown toggle
            document.querySelector('.user-dropdown').addEventListener('click', function(e) {
                e.stopPropagation();
                this.querySelector('.user-dropdown-menu').classList.toggle('show');
            });
            
            // Close user dropdown when clicking outside
            document.addEventListener('click', function(e) {
                const dropdown = document.querySelector('.user-dropdown-menu');
                if (dropdown && dropdown.classList.contains('show') && !dropdown.contains(e.target) && !e.target.closest('.user-dropdown')) {
                    dropdown.classList.remove('show');
                }
            });
}); 