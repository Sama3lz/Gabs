<script>
    document.addEventListener('DOMContentLoaded', function() {
        const hamburgerButton = document.getElementById('hamburger-button');
        const navLinks = document.getElementById('main-nav-links');
        if (hamburgerButton && navLinks) {
            hamburgerButton.addEventListener('click', function() {
                navLinks.classList.toggle('show-menu');
            });
        }
    });
</script>
