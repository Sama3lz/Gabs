<script>
    function updateUnitOptions(selectElement) {
        const selectedOption = selectElement.options[selectElement.selectedIndex];
        const ingredientType = selectedOption.getAttribute('data-type');
        const form = selectElement.closest('form');
        const unitSelect = form.querySelector('select[name="unit"]');
        const dryUnits = unitSelect.querySelectorAll('.dry-unit');
        const wetUnits = unitSelect.querySelectorAll('.wet-unit');
        if (ingredientType === 'Dry') {
            dryUnits.forEach(opt => opt.style.display = 'block');
            wetUnits.forEach(opt => opt.style.display = 'none');
            unitSelect.value = 'g';
        } else if (ingredientType === 'Wet') {
            dryUnits.forEach(opt => opt.style.display = 'none');
            wetUnits.forEach(opt => opt.style.display = 'block');
            unitSelect.value = 'ml';
        } else {
            dryUnits.forEach(opt => opt.style.display = 'block');
            wetUnits.forEach(opt => opt.style.display = 'none');
            unitSelect.value = 'g';
        }
    }
    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('select[name="ingredient_ID"]').forEach(selector => {
            if (selector.closest('form')) updateUnitOptions(selector);
        });
    });
</script>
