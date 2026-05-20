<div class="modal-overlay" id="addModal">
    <div class="modal-content">
        <h3>Confirm Action</h3>
        <p>Are you sure you want to add this ingredient?</p>
        <div class="modal-buttons">
            <button class="btn-yes" onclick="confirmAddIngredient()">Yes</button>
            <button class="btn-no" onclick="closeModal('addModal')">No</button>
        </div>
    </div>
</div>
<div class="modal-overlay" id="updateModal">
    <div class="modal-content">
        <h3>Confirm Action</h3>
        <p>Are you sure you want to update this stock?</p>
        <div class="modal-buttons">
            <button class="btn-yes" onclick="confirmUpdate()">Yes</button>
            <button class="btn-no" onclick="closeModal('updateModal')">No</button>
        </div>
    </div>
</div>
<div class="modal-overlay" id="deleteModal">
    <div class="modal-content">
        <h3>Confirm Action</h3>
        <p id="deleteModalText">Are you sure you want to delete this ingredient?</p>
        <div class="modal-buttons">
            <button class="btn-yes" onclick="confirmDelete()">Yes</button>
            <button class="btn-no" onclick="closeModal('deleteModal')">No</button>
        </div>
    </div>
</div>
