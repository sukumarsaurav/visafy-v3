<?php
// Set page variables
$page_title = "Manage Services";
$page_header = "Visa Services";

// Include header (handles session and authentication)
require_once 'includes/header.php';

// Get entity_id from database
$stmt = $conn->prepare("SELECT id FROM professional_entities WHERE user_id = ?");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows === 0) {
    echo "<div class='alert alert-danger'>Professional profile not found. Please complete your profile first.</div>";
    require_once 'includes/footer.php';
    exit;
}
$entity = $result->fetch_assoc();
$entity_id = $entity['id'];

// Check if form submitted to add or edit a service
$success_message = "";
$error_message = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'add_service' || $_POST['action'] === 'edit_service') {
            // Validate form inputs
            $required_fields = ['country_id', 'visa_type_id', 'service_type_id', 'name', 'description', 'price'];
            $errors = [];
            
            foreach ($required_fields as $field) {
                if (empty($_POST[$field])) {
                    $errors[] = ucfirst(str_replace('_', ' ', $field)) . " is required";
                }
            }
            
            if (!is_numeric($_POST['price']) || floatval($_POST['price']) <= 0) {
                $errors[] = "Price must be a positive number";
            }
            
            if (isset($_POST['estimated_processing_days']) && !empty($_POST['estimated_processing_days']) && (!is_numeric($_POST['estimated_processing_days']) || intval($_POST['estimated_processing_days']) < 1)) {
                $errors[] = "Processing days must be a positive number";
            }
            
            if (isset($_POST['success_rate']) && !empty($_POST['success_rate']) && (!is_numeric($_POST['success_rate']) || floatval($_POST['success_rate']) < 0 || floatval($_POST['success_rate']) > 100)) {
                $errors[] = "Success rate must be between 0 and 100";
            }
            
            if (empty($errors)) {
                try {
                    $conn->begin_transaction();
                    
                    $country_id = intval($_POST['country_id']);
                    $visa_type_id = intval($_POST['visa_type_id']);
                    $service_type_id = intval($_POST['service_type_id']);
                    $name = trim($_POST['name']);
                    $description = trim($_POST['description']);
                    $price = floatval($_POST['price']);
                    $estimated_processing_days = !empty($_POST['estimated_processing_days']) ? intval($_POST['estimated_processing_days']) : null;
                    $success_rate = !empty($_POST['success_rate']) ? floatval($_POST['success_rate']) : null;
                    $requirements = trim($_POST['requirements'] ?? '');
                    
                    if ($_POST['action'] === 'add_service') {
                        // Check if service already exists
                        $check_stmt = $conn->prepare("SELECT id FROM professional_visa_services WHERE entity_id = ? AND country_id = ? AND visa_type_id = ? AND service_type_id = ? AND deleted_at IS NULL");
                        $check_stmt->bind_param("iiii", $entity_id, $country_id, $visa_type_id, $service_type_id);
                        $check_stmt->execute();
                        if ($check_stmt->get_result()->num_rows > 0) {
                            throw new Exception("A service with this country, visa type, and service type already exists");
                        }
                        
                        // Insert new service
                        $stmt = $conn->prepare("INSERT INTO professional_visa_services (entity_id, country_id, visa_type_id, service_type_id, name, description, price, estimated_processing_days, success_rate, requirements) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                        $stmt->bind_param("iiiiisdids", $entity_id, $country_id, $visa_type_id, $service_type_id, $name, $description, $price, $estimated_processing_days, $success_rate, $requirements);
                        $stmt->execute();
                        $service_id = $conn->insert_id;
                        
                        // Handle consultation modes
                        if (isset($_POST['consultation_modes']) && is_array($_POST['consultation_modes'])) {
                            foreach ($_POST['consultation_modes'] as $mode_id) {
                                $price_adjustment = isset($_POST['price_adjustment_'.$mode_id]) ? floatval($_POST['price_adjustment_'.$mode_id]) : 0;
                                $additional_info = isset($_POST['additional_info_'.$mode_id]) ? trim($_POST['additional_info_'.$mode_id]) : '';
                                $is_default = isset($_POST['default_mode']) && $_POST['default_mode'] == $mode_id ? 1 : 0;
                                
                                $mode_stmt = $conn->prepare("INSERT INTO visa_service_consultation_modes (visa_service_id, mode_id, price_adjustment, additional_info, is_default) VALUES (?, ?, ?, ?, ?)");
                                $mode_stmt->bind_param("iidsi", $service_id, $mode_id, $price_adjustment, $additional_info, $is_default);
                                $mode_stmt->execute();
                            }
                        }
                        
                        // Handle required documents
                        if (isset($_POST['required_documents']) && is_array($_POST['required_documents'])) {
                            // First, if editing, delete existing document requirements
                            $delete_docs_stmt = $conn->prepare("DELETE FROM visa_required_documents WHERE visa_type_id = ?");
                            $delete_docs_stmt->bind_param("i", $visa_type_id);
                            $delete_docs_stmt->execute();
                            
                            // Add new document requirements
                            foreach ($_POST['required_documents'] as $doc_id) {
                                $is_mandatory = isset($_POST['doc_mandatory_'.$doc_id]) ? 1 : 0;
                                $additional_requirements = isset($_POST['doc_requirements_'.$doc_id]) ? trim($_POST['doc_requirements_'.$doc_id]) : '';
                                
                                $doc_stmt = $conn->prepare("INSERT INTO visa_required_documents 
                                                           (visa_type_id, document_type_id, is_mandatory, additional_requirements) 
                                                           VALUES (?, ?, ?, ?)");
                                $doc_stmt->bind_param("iiis", $visa_type_id, $doc_id, $is_mandatory, $additional_requirements);
                                $doc_stmt->execute();
                            }
                        }
                        
                        $success_message = "Service added successfully!";
                    } else { // Edit service
                        $service_id = intval($_POST['service_id']);
                        
                        // Verify service belongs to this professional
                        $check_stmt = $conn->prepare("SELECT id FROM professional_visa_services WHERE id = ? AND entity_id = ?");
                        $check_stmt->bind_param("ii", $service_id, $entity_id);
                        $check_stmt->execute();
                        if ($check_stmt->get_result()->num_rows === 0) {
                            throw new Exception("Service not found or you don't have permission to edit it");
                        }
                        
                        // Update service
                        $stmt = $conn->prepare("UPDATE professional_visa_services SET country_id = ?, visa_type_id = ?, service_type_id = ?, name = ?, description = ?, price = ?, estimated_processing_days = ?, success_rate = ?, requirements = ?, updated_at = NOW() WHERE id = ?");
                        $stmt->bind_param("iiissdiisi", $country_id, $visa_type_id, $service_type_id, $name, $description, $price, $estimated_processing_days, $success_rate, $requirements, $service_id);
                        $stmt->execute();
                        
                        // Delete existing consultation modes
                        $delete_stmt = $conn->prepare("DELETE FROM visa_service_consultation_modes WHERE visa_service_id = ?");
                        $delete_stmt->bind_param("i", $service_id);
                        $delete_stmt->execute();
                        
                        // Add new consultation modes
                        if (isset($_POST['consultation_modes']) && is_array($_POST['consultation_modes'])) {
                            foreach ($_POST['consultation_modes'] as $mode_id) {
                                $price_adjustment = isset($_POST['price_adjustment_'.$mode_id]) ? floatval($_POST['price_adjustment_'.$mode_id]) : 0;
                                $additional_info = isset($_POST['additional_info_'.$mode_id]) ? trim($_POST['additional_info_'.$mode_id]) : '';
                                $is_default = isset($_POST['default_mode']) && $_POST['default_mode'] == $mode_id ? 1 : 0;
                                
                                $mode_stmt = $conn->prepare("INSERT INTO visa_service_consultation_modes (visa_service_id, mode_id, price_adjustment, additional_info, is_default) VALUES (?, ?, ?, ?, ?)");
                                $mode_stmt->bind_param("iidsi", $service_id, $mode_id, $price_adjustment, $additional_info, $is_default);
                                $mode_stmt->execute();
                            }
                        }
                        
                        // Handle required documents
                        if (isset($_POST['required_documents']) && is_array($_POST['required_documents'])) {
                            // First, if editing, delete existing document requirements
                            $delete_docs_stmt = $conn->prepare("DELETE FROM visa_required_documents WHERE visa_type_id = ?");
                            $delete_docs_stmt->bind_param("i", $visa_type_id);
                            $delete_docs_stmt->execute();
                            
                            // Add new document requirements
                            foreach ($_POST['required_documents'] as $doc_id) {
                                $is_mandatory = isset($_POST['doc_mandatory_'.$doc_id]) ? 1 : 0;
                                $additional_requirements = isset($_POST['doc_requirements_'.$doc_id]) ? trim($_POST['doc_requirements_'.$doc_id]) : '';
                                
                                $doc_stmt = $conn->prepare("INSERT INTO visa_required_documents 
                                                           (visa_type_id, document_type_id, is_mandatory, additional_requirements) 
                                                           VALUES (?, ?, ?, ?)");
                                $doc_stmt->bind_param("iiis", $visa_type_id, $doc_id, $is_mandatory, $additional_requirements);
                                $doc_stmt->execute();
                            }
                        }
                        
                        $success_message = "Service updated successfully!";
                    }
                    
                    $conn->commit();
                } catch (Exception $e) {
                    $conn->rollback();
                    $error_message = "Error: " . $e->getMessage();
                }
            } else {
                $error_message = implode("<br>", $errors);
            }
        } elseif ($_POST['action'] === 'delete_service') {
            // Soft delete a service
            if (isset($_POST['service_id']) && is_numeric($_POST['service_id'])) {
                $service_id = intval($_POST['service_id']);
                
                // Verify service belongs to this professional
                $check_stmt = $conn->prepare("SELECT id FROM professional_visa_services WHERE id = ? AND entity_id = ?");
                $check_stmt->bind_param("ii", $service_id, $entity_id);
                $check_stmt->execute();
                if ($check_stmt->get_result()->num_rows === 0) {
                    $error_message = "Service not found or you don't have permission to delete it";
                } else {
                    // Soft delete the service
                    $stmt = $conn->prepare("UPDATE professional_visa_services SET deleted_at = NOW() WHERE id = ?");
                    $stmt->bind_param("i", $service_id);
                    if ($stmt->execute()) {
                        $success_message = "Service deleted successfully!";
                    } else {
                        $error_message = "Failed to delete service: " . $conn->error;
                    }
                }
            } else {
                $error_message = "Invalid service ID";
            }
        }
    }
}

// Fetch all active services for this professional
$services_sql = "
    SELECT pvs.*, 
           c.name AS country_name, 
           c.code AS country_code,
           vt.name AS visa_type_name, 
           st.name AS service_type_name,
           (SELECT COUNT(*) FROM visa_service_consultation_modes WHERE visa_service_id = pvs.id) AS consultation_modes_count
    FROM professional_visa_services pvs
    JOIN countries c ON pvs.country_id = c.id
    JOIN visa_types vt ON pvs.visa_type_id = vt.id
    JOIN service_types st ON pvs.service_type_id = st.id
    WHERE pvs.entity_id = ? AND pvs.deleted_at IS NULL
    ORDER BY c.name, vt.name, st.name
";
$stmt = $conn->prepare($services_sql);
$stmt->bind_param("i", $entity_id);
$stmt->execute();
$services = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get countries for dropdown
$countries_sql = "SELECT id, name, code FROM countries WHERE is_active = 1 ORDER BY name";
$countries = $conn->query($countries_sql)->fetch_all(MYSQLI_ASSOC);

// Get service types for dropdown
$service_types_sql = "SELECT id, name, description FROM service_types WHERE is_active = 1 ORDER BY name";
$service_types = $conn->query($service_types_sql)->fetch_all(MYSQLI_ASSOC);

// Get consultation modes for checkboxes
$consultation_modes_sql = "SELECT id, name, description FROM consultation_modes WHERE is_active = 1 ORDER BY name";
$consultation_modes = $conn->query($consultation_modes_sql)->fetch_all(MYSQLI_ASSOC);

// Get required documents for a visa type
$visa_type_id = 123; // Example visa type ID
$stmt = $conn->prepare("
    SELECT d.name as document_name, d.description as document_description, c.name as category_name,
           vrd.is_mandatory, vrd.additional_requirements, vrd.order_display
    FROM visa_required_documents vrd
    JOIN document_types d ON vrd.document_type_id = d.id
    JOIN document_categories c ON d.category_id = c.id
    WHERE vrd.visa_type_id = ?
    ORDER BY vrd.order_display, c.name, d.name
");
$stmt->bind_param("i", $visa_type_id);
$stmt->execute();
$required_docs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
?>

<div class="page-header">
    <div class="page-title">
        <h1><?php echo $page_header; ?></h1>
        <p>Manage your visa services and consultation offerings</p>
    </div>
    <div class="page-actions">
        <button id="add-service-btn" class="btn-primary">
            <i class="fas fa-plus"></i> Add New Service
        </button>
    </div>
</div>

<?php if ($success_message): ?>
    <div class="alert alert-success">
        <i class="fas fa-check-circle"></i> <?php echo $success_message; ?>
        <button type="button" class="close-btn"><i class="fas fa-times"></i></button>
    </div>
<?php endif; ?>

<?php if ($error_message): ?>
    <div class="alert alert-danger">
        <i class="fas fa-exclamation-circle"></i> <?php echo $error_message; ?>
        <button type="button" class="close-btn"><i class="fas fa-times"></i></button>
    </div>
<?php endif; ?>

<div class="content-wrapper">
    <!-- Services List -->
    <div class="services-container">
        <?php if (empty($services)): ?>
            <div class="empty-state">
                <div class="empty-state-icon">
                    <i class="fas fa-briefcase"></i>
                </div>
                <h3>No Services Added Yet</h3>
                <p>Start adding visa services to showcase your expertise to potential clients.</p>
                <button id="empty-add-service-btn" class="btn-primary">
                    <i class="fas fa-plus"></i> Add Your First Service
                </button>
            </div>
        <?php else: ?>
            <?php 
            $current_country = '';
            foreach ($services as $service): 
                if ($current_country != $service['country_name']):
                    if ($current_country != '') echo '</div>'; // Close previous country container
                    $current_country = $service['country_name'];
            ?>
            <div class="country-services">
                <div class="country-header">
                    <h2>
                        <?php if (!empty($service['country_code'])): ?>
                        <img src="../../assets/images/flags/<?php echo strtolower($service['country_code']); ?>.png" alt="<?php echo htmlspecialchars($service['country_name']); ?> flag" class="country-flag">
                        <?php endif; ?>
                        <?php echo htmlspecialchars($service['country_name']); ?>
                    </h2>
                </div>
            <?php endif; ?>
                
                <div class="service-card" data-id="<?php echo $service['id']; ?>">
                    <div class="service-header">
                        <div class="service-title">
                            <h3><?php echo htmlspecialchars($service['name']); ?></h3>
                            <div class="service-tags">
                                <span class="visa-tag"><?php echo htmlspecialchars($service['visa_type_name']); ?></span>
                                <span class="service-type-tag"><?php echo htmlspecialchars($service['service_type_name']); ?></span>
                                <?php if (!empty($service['success_rate'])): ?>
                                <span class="success-rate-tag"><?php echo htmlspecialchars($service['success_rate']); ?>% Success Rate</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="service-price">
                            $<?php echo number_format($service['price'], 2); ?>
                        </div>
                    </div>
                    <div class="service-body">
                        <p class="service-description"><?php echo nl2br(htmlspecialchars($service['description'])); ?></p>
                        
                        <div class="service-details">
                            <?php if (!empty($service['estimated_processing_days'])): ?>
                            <div class="detail-item">
                                <i class="fas fa-calendar-day"></i>
                                <span>Processing Time: <?php echo htmlspecialchars($service['estimated_processing_days']); ?> days</span>
                            </div>
                            <?php endif; ?>
                            
                            <div class="detail-item">
                                <i class="fas fa-comments"></i>
                                <span>Consultation Modes: <?php echo $service['consultation_modes_count']; ?></span>
                            </div>
                        </div>
                    </div>
                    <div class="service-actions">
                        <button class="btn-edit edit-service-btn" data-id="<?php echo $service['id']; ?>">
                            <i class="fas fa-edit"></i> Edit
                        </button>
                        <button class="btn-delete delete-service-btn" data-id="<?php echo $service['id']; ?>">
                            <i class="fas fa-trash"></i> Delete
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>
            </div><!-- Close the last country container -->
        <?php endif; ?>
    </div>
</div>

<!-- Modal for Adding/Editing Service -->
<div class="modal" id="service-modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 id="modal-title">Add New Service</h2>
            <button class="modal-close"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <form id="service-form" method="POST" action="">
                <input type="hidden" name="action" id="form-action" value="add_service">
                <input type="hidden" name="service_id" id="service-id" value="">
                
                <div class="form-section">
                    <h3>Visa Information</h3>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="country_id">Country</label>
                            <select id="country_id" name="country_id" required>
                                <option value="">Select a country</option>
                                <?php foreach ($countries as $country): ?>
                                <option value="<?php echo $country['id']; ?>"><?php echo htmlspecialchars($country['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="visa_type_id">Visa Type</label>
                            <select id="visa_type_id" name="visa_type_id" required>
                                <option value="">Select a country first</option>
                            </select>
                        </div>
                    </div>
                </div>
                
                <div class="form-section">
                    <h3>Service Details</h3>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="service_type_id">Service Type</label>
                            <select id="service_type_id" name="service_type_id" required>
                                <option value="">Select a service type</option>
                                <?php foreach ($service_types as $type): ?>
                                <option value="<?php echo $type['id']; ?>" data-description="<?php echo htmlspecialchars($type['description']); ?>"><?php echo htmlspecialchars($type['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <small id="service-type-description" class="form-text"></small>
                        </div>
                        <div class="form-group">
                            <label for="price">Price ($)</label>
                            <input type="number" id="price" name="price" step="0.01" min="0" required>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="name">Service Name</label>
                        <input type="text" id="name" name="name" required maxlength="100">
                        <small class="form-text">Create a catchy name for your service (e.g. "Express Student Visa Application Support")</small>
                    </div>
                    
                    <div class="form-group">
                        <label for="description">Service Description</label>
                        <textarea id="description" name="description" rows="4" required></textarea>
                        <small class="form-text">Describe what your service includes, what clients can expect, and why they should choose this service</small>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="estimated_processing_days">Estimated Processing Days</label>
                            <input type="number" id="estimated_processing_days" name="estimated_processing_days" min="1">
                            <small class="form-text">Leave empty if varies</small>
                        </div>
                        <div class="form-group">
                            <label for="success_rate">Success Rate (%)</label>
                            <input type="number" id="success_rate" name="success_rate" min="0" max="100">
                            <small class="form-text">Optional, leave empty if unknown</small>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="requirements">Requirements & Prerequisites</label>
                        <textarea id="requirements" name="requirements" rows="3"></textarea>
                        <small class="form-text">List any requirements or prerequisites clients should know about</small>
                    </div>
                </div>
                
                <div class="form-section">
                    <h3>Consultation Modes</h3>
                    <p class="form-section-desc">Select how clients can consult with you regarding this service</p>
                    
                    <div class="consultation-modes">
                        <?php foreach ($consultation_modes as $mode): ?>
                        <div class="mode-item">
                            <div class="mode-checkbox">
                                <input type="checkbox" id="mode_<?php echo $mode['id']; ?>" name="consultation_modes[]" value="<?php echo $mode['id']; ?>" class="mode-checkbox-input">
                                <label for="mode_<?php echo $mode['id']; ?>" class="mode-checkbox-label">
                                    <?php echo htmlspecialchars($mode['name']); ?> 
                                    <span class="mode-description"><?php echo htmlspecialchars($mode['description']); ?></span>
                                </label>
                                <input type="radio" id="default_mode_<?php echo $mode['id']; ?>" name="default_mode" value="<?php echo $mode['id']; ?>" class="default-mode-radio">
                                <label for="default_mode_<?php echo $mode['id']; ?>" class="default-mode-label">Set as Default</label>
                            </div>
                            
                            <div class="mode-details" id="mode_details_<?php echo $mode['id']; ?>">
                                <div class="form-row">
                                    <div class="form-group">
                                        <label for="price_adjustment_<?php echo $mode['id']; ?>">Price Adjustment ($)</label>
                                        <input type="number" id="price_adjustment_<?php echo $mode['id']; ?>" name="price_adjustment_<?php echo $mode['id']; ?>" step="0.01" value="0">
                                        <small class="form-text">Amount to add/subtract from base price. Use negative for discounts.</small>
                                    </div>
                                    <div class="form-group">
                                        <label for="additional_info_<?php echo $mode['id']; ?>">Additional Information</label>
                                        <input type="text" id="additional_info_<?php echo $mode['id']; ?>" name="additional_info_<?php echo $mode['id']; ?>" placeholder="e.g. Meeting location, hours available">
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <div class="form-section">
                    <h3>Required Documents</h3>
                    <p class="form-section-desc">Specify which documents are required for this visa type</p>
                    
                    <div id="documents-container">
                        <div class="empty-documents">
                            <p>Please select a country and visa type first to view available documents.</p>
                        </div>
                        <!-- Document checkboxes will be loaded here dynamically -->
                    </div>
                </div>
                
                <div class="form-actions">
                    <button type="button" class="btn-secondary modal-cancel">Cancel</button>
                    <button type="submit" class="btn-primary" id="save-service-btn">Save Service</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal" id="delete-modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Delete Service</h2>
            <button class="modal-close"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <p>Are you sure you want to delete this service? This action cannot be undone.</p>
            <form id="delete-form" method="POST" action="">
                <input type="hidden" name="action" value="delete_service">
                <input type="hidden" name="service_id" id="delete-service-id" value="">
                
                <div class="form-actions">
                    <button type="button" class="btn-secondary modal-cancel">Cancel</button>
                    <button type="submit" class="btn-danger">Delete Service</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- JavaScript for Dynamic Interaction -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Close alerts
    const closeButtons = document.querySelectorAll('.alert .close-btn');
    closeButtons.forEach(button => {
        button.addEventListener('click', function() {
            this.parentElement.remove();
        });
    });
    
    // Country change - load visa types
    const countrySelect = document.getElementById('country_id');
    const visaTypeSelect = document.getElementById('visa_type_id');
    
    countrySelect.addEventListener('change', function() {
        const countryId = this.value;
        
        // Reset visa type dropdown
        visaTypeSelect.innerHTML = '<option value="">Select a visa type</option>';
        
        if (countryId) {
            // Fetch visa types for the selected country
            fetch(`ajax/get_visa_types.php?country_id=${countryId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        data.visa_types.forEach(type => {
                            const option = document.createElement('option');
                            option.value = type.id;
                            option.textContent = type.name;
                            visaTypeSelect.appendChild(option);
                        });
                    } else {
                        console.error('Error loading visa types:', data.error);
                    }
                })
                .catch(error => {
                    console.error('Error fetching visa types:', error);
                });
        }
    });
    
    // Service type change - update description
    const serviceTypeSelect = document.getElementById('service_type_id');
    const serviceTypeDesc = document.getElementById('service-type-description');
    
    serviceTypeSelect.addEventListener('change', function() {
        const option = this.options[this.selectedIndex];
        serviceTypeDesc.textContent = option ? option.dataset.description || '' : '';
    });
    
    // Toggle consultation mode details
    const modeCheckboxes = document.querySelectorAll('.mode-checkbox-input');
    
    modeCheckboxes.forEach(checkbox => {
        checkbox.addEventListener('change', function() {
            const modeId = this.value;
            const detailsDiv = document.getElementById(`mode_details_${modeId}`);
            const defaultRadio = document.getElementById(`default_mode_${modeId}`);
            
            detailsDiv.style.display = this.checked ? 'block' : 'none';
            defaultRadio.disabled = !this.checked;
            
            // If this is the first checked box, make it the default
            if (this.checked) {
                const checkedBoxes = document.querySelectorAll('.mode-checkbox-input:checked');
                if (checkedBoxes.length === 1) {
                    defaultRadio.checked = true;
                }
            } else {
                defaultRadio.checked = false;
                // If there's only one checkbox left checked, make it the default
                const checkedBoxes = document.querySelectorAll('.mode-checkbox-input:checked');
                if (checkedBoxes.length === 1) {
                    const remainingModeId = checkedBoxes[0].value;
                    document.getElementById(`default_mode_${remainingModeId}`).checked = true;
                }
            }
        });
    });
    
    // "Add Service" button click
    const addServiceBtn = document.getElementById('add-service-btn');
    const emptyAddServiceBtn = document.getElementById('empty-add-service-btn');
    const serviceModal = document.getElementById('service-modal');
    const modalTitle = document.getElementById('modal-title');
    const formAction = document.getElementById('form-action');
    const serviceId = document.getElementById('service-id');
    const serviceForm = document.getElementById('service-form');
    
    function openAddServiceModal() {
        // Reset form
        serviceForm.reset();
        
        // Set form mode to add
        modalTitle.textContent = 'Add New Service';
        formAction.value = 'add_service';
        serviceId.value = '';
        
        // Reset visual state of form elements
        resetVisualState();
        
        // Show the modal
        serviceModal.classList.add('show');
    }
    
    addServiceBtn?.addEventListener('click', openAddServiceModal);
    emptyAddServiceBtn?.addEventListener('click', openAddServiceModal);
    
    // "Edit Service" button click
    const editButtons = document.querySelectorAll('.edit-service-btn');
    
    editButtons.forEach(button => {
        button.addEventListener('click', function() {
            const serviceId = this.dataset.id;
            
            // Fetch service data
            fetch(`ajax/get_service.php?service_id=${serviceId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Set form mode to edit
                        modalTitle.textContent = 'Edit Service';
                        formAction.value = 'edit_service';
                        document.getElementById('service-id').value = serviceId;
                        
                        // Reset visual state
                        resetVisualState();
                        
                        // Populate form with service data
                        populateForm(data.service);
                        
                        // Show the modal
                        serviceModal.classList.add('show');
                    } else {
                        alert('Error loading service data: ' + data.error);
                    }
                })
                .catch(error => {
                    console.error('Error fetching service data:', error);
                    alert('Could not load service data. Please try again.');
                });
        });
    });
    
    function resetVisualState() {
        // Reset consultation modes
        modeCheckboxes.forEach(checkbox => {
            checkbox.checked = false;
            const modeId = checkbox.value;
            document.getElementById(`mode_details_${modeId}`).style.display = 'none';
            document.getElementById(`default_mode_${modeId}`).disabled = true;
            document.getElementById(`default_mode_${modeId}`).checked = false;
        });
        
        // Reset service type description
        document.getElementById('service-type-description').textContent = '';
        
        // Reset visa type dropdown
        visaTypeSelect.innerHTML = '<option value="">Select a country first</option>';
    }
    
    function populateForm(service) {
        // Set basic fields
        document.getElementById('country_id').value = service.country_id;
        document.getElementById('name').value = service.name;
        document.getElementById('price').value = service.price;
        document.getElementById('description').value = service.description;
        document.getElementById('requirements').value = service.requirements || '';
        document.getElementById('estimated_processing_days').value = service.estimated_processing_days || '';
        document.getElementById('success_rate').value = service.success_rate || '';
        
        // Load visa types for the country and then set the selected one
        fetch(`ajax/get_visa_types.php?country_id=${service.country_id}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    visaTypeSelect.innerHTML = '<option value="">Select a visa type</option>';
                    data.visa_types.forEach(type => {
                        const option = document.createElement('option');
                        option.value = type.id;
                        option.textContent = type.name;
                        visaTypeSelect.appendChild(option);
                    });
                    
                    // Set selected visa type
                    visaTypeSelect.value = service.visa_type_id;
                }
            });
        
        // Set service type and description
        document.getElementById('service_type_id').value = service.service_type_id;
        const serviceTypeOption = serviceTypeSelect.options[serviceTypeSelect.selectedIndex];
        if (serviceTypeOption) {
            document.getElementById('service-type-description').textContent = serviceTypeOption.dataset.description || '';
        }
        
        // Set consultation modes
        if (service.consultation_modes && service.consultation_modes.length > 0) {
            service.consultation_modes.forEach(mode => {
                const checkbox = document.getElementById(`mode_${mode.mode_id}`);
                if (checkbox) {
                    checkbox.checked = true;
                    document.getElementById(`mode_details_${mode.mode_id}`).style.display = 'block';
                    document.getElementById(`default_mode_${mode.mode_id}`).disabled = false;
                    document.getElementById(`default_mode_${mode.mode_id}`).checked = mode.is_default === 1;
                    document.getElementById(`price_adjustment_${mode.mode_id}`).value = mode.price_adjustment || 0;
                    document.getElementById(`additional_info_${mode.mode_id}`).value = mode.additional_info || '';
                }
            });
        }
    }
    
    // "Delete Service" button click
    const deleteButtons = document.querySelectorAll('.delete-service-btn');
    const deleteModal = document.getElementById('delete-modal');
    
    deleteButtons.forEach(button => {
        button.addEventListener('click', function() {
            const serviceId = this.dataset.id;
            document.getElementById('delete-service-id').value = serviceId;
            deleteModal.classList.add('show');
        });
    });
    
    // Close modals
    const modalCloseButtons = document.querySelectorAll('.modal-close, .modal-cancel');
    
    modalCloseButtons.forEach(button => {
        button.addEventListener('click', function() {
            const modal = this.closest('.modal');
            modal.classList.remove('show');
        });
    });
    
    // Close modal when clicking outside
    window.addEventListener('click', function(event) {
        const modals = document.querySelectorAll('.modal');
        modals.forEach(modal => {
            if (event.target === modal) {
                modal.classList.remove('show');
            }
        });
    });
    
    // Form validation before submit
    serviceForm.addEventListener('submit', function(event) {
        const checkedModes = document.querySelectorAll('.mode-checkbox-input:checked');
        if (checkedModes.length === 0) {
            event.preventDefault();
            alert('Please select at least one consultation mode.');
            return;
        }
        
        const defaultMode = document.querySelector('.default-mode-radio:checked');
        if (!defaultMode) {
            event.preventDefault();
            alert('Please select a default consultation mode.');
            return;
        }
    });

    // Visa type change - load documents
    visaTypeSelect.addEventListener('change', function() {
        const visaTypeId = this.value;
        const documentsContainer = document.getElementById('documents-container');
        
        if (visaTypeId) {
            // Show loading message
            documentsContainer.innerHTML = '<div class="loading-documents">Loading available documents...</div>';
            
            // Fetch documents for the selected visa type
            fetch(`ajax/get_documents.php?visa_type_id=${visaTypeId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        loadDocuments(data.categories);
                    } else {
                        documentsContainer.innerHTML = `<div class="error-message">${data.error || 'Error loading documents'}</div>`;
                    }
                })
                .catch(error => {
                    console.error('Error fetching documents:', error);
                    documentsContainer.innerHTML = '<div class="error-message">Failed to load documents. Please try again.</div>';
                });
        } else {
            documentsContainer.innerHTML = '<div class="empty-documents"><p>Please select a country and visa type first to view available documents.</p></div>';
        }
    });

    function loadDocuments(categories) {
        const documentsContainer = document.getElementById('documents-container');
        
        if (!categories || categories.length === 0) {
            documentsContainer.innerHTML = '<div class="empty-documents"><p>No document types found for this visa type.</p></div>';
            return;
        }
        
        let html = '';
        
        categories.forEach(category => {
            html += `
                <div class="document-category">
                    <h4>${category.name}</h4>
                    <div class="document-list">
            `;
            
            category.documents.forEach(doc => {
                html += `
                    <div class="document-item">
                        <div class="document-checkbox">
                            <input type="checkbox" id="doc_${doc.id}" name="required_documents[]" value="${doc.id}" 
                                ${doc.is_required ? 'checked' : ''} class="document-checkbox-input">
                            <label for="doc_${doc.id}" class="document-checkbox-label">${doc.name}</label>
                        </div>
                        <div class="document-info">
                            <small class="document-description">${doc.description || ''}</small>
                        </div>
                        <div class="document-details" id="doc_details_${doc.id}" style="display: ${doc.is_required ? 'block' : 'none'}">
                            <div class="form-group">
                                <label for="doc_requirements_${doc.id}">Additional Requirements</label>
                                <textarea id="doc_requirements_${doc.id}" name="doc_requirements_${doc.id}" rows="2">${doc.additional_requirements || ''}</textarea>
                                <small class="form-text">Specific formatting or special requirements for this document</small>
                            </div>
                            <div class="form-checkbox">
                                <input type="checkbox" id="doc_mandatory_${doc.id}" name="doc_mandatory_${doc.id}" ${doc.is_required ? 'checked' : ''}>
                                <label for="doc_mandatory_${doc.id}">This document is mandatory</label>
                            </div>
                        </div>
                    </div>
                `;
            });
            
            html += `
                    </div>
                </div>
            `;
        });
        
        documentsContainer.innerHTML = html;
        
        // Add event listeners for document checkboxes
        document.querySelectorAll('.document-checkbox-input').forEach(checkbox => {
            checkbox.addEventListener('change', function() {
                const docId = this.value;
                const detailsDiv = document.getElementById(`doc_details_${docId}`);
                detailsDiv.style.display = this.checked ? 'block' : 'none';
                
                // When unchecked, also uncheck the mandatory box
                if (!this.checked) {
                    document.getElementById(`doc_mandatory_${docId}`).checked = false;
                }
            });
        });
    }
});
</script>

<?php require_once 'includes/footer.php'; ?>
