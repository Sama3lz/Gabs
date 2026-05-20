<script>
    const invCanUpdate = <?= !empty($inv_can_update) ? 'true' : 'false' ?>;
    const invCanDelete = <?= !empty($inv_can_delete) ? 'true' : 'false' ?>;
    const currentFilter = '<?= $filter ?>';
    const currentSearch = '<?= htmlspecialchars($search, ENT_QUOTES) ?>';
    let pendingForm = null;

    function openModal(modalId, form = null) {
        if (form && form.getAttribute('data-confirmed') === 'true') return;
        pendingForm = form;
        document.getElementById(modalId).classList.add('active');
    }
    function closeModal(modalId) {
        document.getElementById(modalId).classList.remove('active');
        pendingForm = null;
    }
    function confirmAddIngredient() {
        if (!pendingForm) return;
        const i = document.createElement('input');
        i.type = 'hidden'; i.name = 'add_ingredient'; i.value = '1';
        pendingForm.appendChild(i);
        pendingForm.setAttribute('data-confirmed', 'true');
        pendingForm.submit();
    }
    function confirmUpdate() {
        if (!pendingForm) return;
        const i = document.createElement('input');
        i.type = 'hidden'; i.name = 'update_ingredient'; i.value = '1';
        pendingForm.appendChild(i);
        pendingForm.setAttribute('data-confirmed', 'true');
        pendingForm.submit();
    }
    function confirmDelete() {
        if (!pendingForm) return;
        const i = document.createElement('input');
        i.type = 'hidden'; i.name = 'delete_ingredient'; i.value = '1';
        pendingForm.appendChild(i);
        pendingForm.setAttribute('data-confirmed', 'true');
        pendingForm.submit();
    }
</script>
