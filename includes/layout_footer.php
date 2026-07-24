</div>
<script>
function dnbCopiar(id) {
  const el = document.getElementById(id);
  if (!el) return;
  navigator.clipboard.writeText(el.innerText).then(() => {
    const btn = document.getElementById('btn-' + id);
    if (btn) { const t = btn.innerText; btn.innerText = 'Copiado!'; setTimeout(() => btn.innerText = t, 1500); }
  });
}
</script>
</body>
</html>
