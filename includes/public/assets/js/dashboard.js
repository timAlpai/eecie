function gceToggleSidebar() {
  const sidebar = document.getElementById("gce-sidebar");
  sidebar.classList.toggle("active");
}

fetch(EECIE_CRM.rest_url + 'eecie-crm/v1/contacts', {
  headers: { 'X-WP-Nonce': EECIE_CRM.nonce }
})
