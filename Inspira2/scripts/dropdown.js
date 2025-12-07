function toggleMenu(id) {
  const menu = document.getElementById(id);
  menu.style.display = (menu.style.display === "block") ? "none" : "block";
}

// Close menu when clicking outside
document.addEventListener("click", function(e) {
  if (!e.target.classList.contains("more-btn")) {
    document.querySelectorAll(".dropdown-menu").forEach(menu => {
      menu.style.display = "none";
    });
  }
});

function openBoardModal(title, poster, type) {
  document.getElementById("modal_title").value = title;
  document.getElementById("modal_poster").value = poster;
  document.getElementById("modal_type").value = type;
  document.getElementById("boardModal").style.display = "flex";
}

function closeBoardModal() {
  document.getElementById("boardModal").style.display = "none";
}
