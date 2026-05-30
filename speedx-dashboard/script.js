// Check if user is logged in
function checkAuth() {
    fetch('check_auth.php')
        .then(response => response.json())
        .then(data => {
            if (!data.logged_in) {
                window.location.href = 'index.html';
            } else {
                displayUserInfo(data.user);
                loadDepartments();
                loadAISuggestions();
                checkAdminAccess(data.user);
            }
        });
}

// Display user information
function displayUserInfo(user) {
    document.getElementById('userName').textContent = user.full_name;
    document.getElementById('userRole').textContent = user.role;
}

// Check if user has admin access
function checkAdminAccess(user) {
    const adminRoles = ['ceo', 'data_analyst_manager', 'audit_manager', 'mis_manager'];
    const adminElements = document.querySelectorAll('.admin-only');
    
    if (adminRoles.includes(user.role)) {
        adminElements.forEach(el => el.style.display = 'block');
    } else {
        adminElements.forEach(el => el.style.display = 'none');
    }
}

// Load departments
function loadDepartments() {
    fetch('get_departments.php')
        .then(response => response.json())
        .then(departments => {
            const grid = document.getElementById('departmentGrid');
            grid.innerHTML = '';
            
            departments.forEach(dept => {
                const card = document.createElement('div');
                card.className = 'department-card';
                card.onclick = () => viewDepartment(dept.id);
                card.innerHTML = `
                    <h3>${dept.name}</h3>
                    <p>${dept.description}</p>
                    <span class="card-arrow">→</span>
                `;
                grid.appendChild(card);
            });
        });
}

// View specific department
function viewDepartment(deptId) {
    window.location.href = `department.html?id=${deptId}`;
}

// Load AI suggestions
function loadAISuggestions() {
    fetch('get_ai_suggestions.php')
        .then(response => response.json())
        .then(suggestions => {
            const grid = document.getElementById('suggestionsGrid');
            grid.innerHTML = '';
            
            suggestions.forEach(suggestion => {
                const card = document.createElement('div');
                card.className = 'suggestion-card';
                card.innerHTML = `
                    <div class="suggestion-priority priority-${suggestion.priority}">
                        ${suggestion.priority.toUpperCase()}
                    </div>
                    <p>${suggestion.suggestion_text}</p>
                    <small>Department: ${suggestion.department_name}</small>
                `;
                grid.appendChild(card);
            });
        });
}

// Set current date
document.getElementById('currentDate').textContent = new Date().toLocaleDateString('en-US', {
    weekday: 'long',
    year: 'numeric',
    month: 'long',
    day: 'numeric'
});

// Logout function
function logout() {
    fetch('logout.php')
        .then(() => {
            window.location.href = 'index.html';
        });
}

// Initialize dashboard
checkAuth();