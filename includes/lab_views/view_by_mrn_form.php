<?php
// Prevent direct access
if (!isset($pdo) || !isset($action) || !in_array($action, ['view_by_mrn_form', 'view_by_mrn_results'])) die('Access denied');
// Use global $lookup_mrn if reloading after POST error from results view
global $lookup_mrn;
?>
<div class="card">
    <div class="card-content">
        <span class="card-title"><i class="material-icons">person_search</i>View Lab Records by MRN</span>
        <form method="POST" action="?action=view_by_mrn_results">
             <div class="row valign-wrapper" style="margin-bottom: 10px;">
                <div class="input-field col s12 m8 l7">
                    <i class="material-icons prefix">badge</i>
                    <input id="mrn" name="mrn" type="text" class="validate white-text" required value="<?= htmlspecialchars($lookup_mrn ?? '') ?>" onblur="lookupPatientSimple()">
                    <label for="mrn">Enter Patient MRN</label>
                     <span id="patientNameDisplay" class="lookup-box helper-text"></span>
                </div>
                 <div class="col s12 m4 l5">
                     <button type="submit" class="btn waves-effect waves-light" style="margin-top: 10px;">
                        <i class="material-icons left">find_in_page</i>View Records
                    </button>
                 </div>
            </div>
        </form>
    </div>
</div>