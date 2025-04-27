<?php
require_once 'includes/header.php';

// In a real application, you would fetch these values from the database
// Example queries for retrieving data (commented out):

/*
// Get user entity ID
$sql = "SELECT id FROM professional_entities WHERE user_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$entity = $result->fetch_assoc();
$entity_id = $entity['id'];

// Total cases count
$sql = "SELECT COUNT(*) as total FROM visa_applications WHERE professional_entity_id = ? AND deleted_at IS NULL";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $entity_id);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();
$totalCases = $row['total'];

// Active cases count
$sql = "SELECT COUNT(*) as active FROM visa_applications 
        WHERE professional_entity_id = ? 
        AND status IN ('in_progress', 'under_review', 'awaiting_documents') 
        AND deleted_at IS NULL";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $entity_id);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();
$activeCases = $row['active'];

// Pending documents count
$sql = "SELECT COUNT(*) as pending FROM application_documents 
        WHERE application_id IN (SELECT id FROM visa_applications WHERE professional_entity_id = ?) 
        AND status = 'pending' 
        AND deleted_at IS NULL";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $entity_id);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();
$pendingDocuments = $row['pending'];

// Upcoming appointments count
$sql = "SELECT COUNT(*) as upcoming FROM appointments 
        WHERE professional_entity_id = ? 
        AND appointment_date >= CURDATE() 
        AND status = 'confirmed' 
        AND deleted_at IS NULL";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $entity_id);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();
$upcomingAppointments = $row['upcoming'];

// Get recent cases
$sql = "SELECT va.id, va.reference_number, va.status, va.created_at, 
        c.name as country_name, vt.name as visa_type_name,
        CASE 
            WHEN a.entity_type = 'individual' THEN CONCAT(ai.first_name, ' ', ai.last_name)
            ELSE aa.company_name
        END as applicant_name
        FROM visa_applications va
        JOIN countries c ON va.country_id = c.id
        JOIN visa_types vt ON va.visa_type_id = vt.id
        JOIN applicants a ON va.applicant_id = a.id
        LEFT JOIN individual_applicants ai ON a.id = ai.applicant_id AND a.entity_type = 'individual'
        LEFT JOIN company_applicants aa ON a.id = aa.applicant_id AND a.entity_type = 'company'
        WHERE va.professional_entity_id = ?
        AND va.deleted_at IS NULL
        ORDER BY va.created_at DESC
        LIMIT 5";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $entity_id);
$stmt->execute();
$result = $stmt->get_result();
$recentCases = [];
while ($row = $result->fetch_assoc()) {
    $recentCases[] = $row;
}

// Get upcoming appointments
$sql = "SELECT a.id, a.appointment_date, a.start_time, a.end_time, a.mode_id, a.status,
        ap.entity_type,
        CASE 
            WHEN ap.entity_type = 'individual' THEN CONCAT(ia.first_name, ' ', ia.last_name)
            ELSE ca.company_name
        END as applicant_name,
        cm.name as mode_name
        FROM appointments a
        JOIN applicants ap ON a.applicant_id = ap.id
        LEFT JOIN individual_applicants ia ON ap.id = ia.applicant_id AND ap.entity_type = 'individual'
        LEFT JOIN company_applicants ca ON ap.id = ca.applicant_id AND ap.entity_type = 'company'
        JOIN consultation_modes cm ON a.mode_id = cm.id
        WHERE a.professional_entity_id = ?
        AND a.appointment_date >= CURDATE()
        AND a.status = 'confirmed'
        AND a.deleted_at IS NULL
        ORDER BY a.appointment_date ASC, a.start_time ASC
        LIMIT 5";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $entity_id);
$stmt->execute();
$result = $stmt->get_result();
$appointments = [];
while ($row = $result->fetch_assoc()) {
    $appointments[] = $row;
}
*/

// For demonstration, we'll use placeholder data
$totalCases = 0;
$activeCases = 0;
$pendingDocuments = 0;
$upcomingAppointments = 0;
$recentCases = []; // Empty array to simulate no cases
$appointments = []; // Empty array to simulate no appointments
?>

<!-- Statistics Cards -->
<div class="stats-container">
    <div class="stats-card">
        <div class="stats-icon">
            <i class="fas fa-briefcase"></i>
        </div>
        <div class="stats-details">
            <div class="stats-value"><?php echo $totalCases; ?></div>
            <div class="stats-label">Total Cases</div>
        </div>
    </div>
    
    <div class="stats-card">
        <div class="stats-icon">
            <i class="fas fa-clock"></i>
        </div>
        <div class="stats-details">
            <div class="stats-value"><?php echo $activeCases; ?></div>
            <div class="stats-label">Active Cases</div>
        </div>
    </div>
    
    <div class="stats-card">
        <div class="stats-icon">
            <i class="fas fa-file-alt"></i>
        </div>
        <div class="stats-details">
            <div class="stats-value"><?php echo $pendingDocuments; ?></div>
            <div class="stats-label">Pending Documents</div>
        </div>
    </div>
    
    <div class="stats-card">
        <div class="stats-icon">
            <i class="fas fa-calendar-check"></i>
        </div>
        <div class="stats-details">
            <div class="stats-value"><?php echo $upcomingAppointments; ?></div>
            <div class="stats-label">Upcoming Appointments</div>
        </div>
    </div>
</div>

<!-- Recent Cases Section -->
<div class="section">
    <h2 class="section-title">Recent Cases</h2>
    <?php if (empty($recentCases)): ?>
        <div class="empty-state">No recent cases found.</div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>Reference</th>
                        <th>Applicant</th>
                        <th>Visa Type</th>
                        <th>Country</th>
                        <th>Status</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentCases as $case): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($case['reference_number']); ?></td>
                        <td><?php echo htmlspecialchars($case['applicant_name']); ?></td>
                        <td><?php echo htmlspecialchars($case['visa_type_name']); ?></td>
                        <td><?php echo htmlspecialchars($case['country_name']); ?></td>
                        <td><span class="badge bg-<?php echo getStatusColorClass($case['status']); ?>"><?php echo formatStatus($case['status']); ?></span></td>
                        <td><?php echo date('M d, Y', strtotime($case['created_at'])); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- Upcoming Appointments Section -->
<div class="section">
    <h2 class="section-title">Upcoming Appointments</h2>
    <?php if (empty($appointments)): ?>
        <div class="empty-state">No upcoming appointments found.</div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>Applicant</th>
                        <th>Date</th>
                        <th>Time</th>
                        <th>Mode</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($appointments as $appointment): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($appointment['applicant_name']); ?></td>
                        <td><?php echo date('M d, Y', strtotime($appointment['appointment_date'])); ?></td>
                        <td><?php echo date('h:i A', strtotime($appointment['start_time'])) . ' - ' . date('h:i A', strtotime($appointment['end_time'])); ?></td>
                        <td><?php echo htmlspecialchars($appointment['mode_name']); ?></td>
                        <td><span class="badge bg-<?php echo getAppointmentStatusColorClass($appointment['status']); ?>"><?php echo formatStatus($appointment['status']); ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php
// Helper functions
function getStatusColorClass($status) {
    switch ($status) {
        case 'approved':
            return 'success';
        case 'rejected':
            return 'danger';
        case 'in_progress':
            return 'primary';
        case 'under_review':
            return 'info';
        case 'awaiting_documents':
            return 'warning';
        default:
            return 'secondary';
    }
}

function getAppointmentStatusColorClass($status) {
    switch ($status) {
        case 'confirmed':
            return 'success';
        case 'pending':
            return 'warning';
        case 'cancelled':
            return 'danger';
        case 'completed':
            return 'info';
        default:
            return 'secondary';
    }
}

function formatStatus($status) {
    return ucwords(str_replace('_', ' ', $status));
}

require_once 'includes/footer.php';
?>
