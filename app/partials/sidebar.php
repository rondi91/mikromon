<aside class="sidebar" id="sidebar">
  <div class="section-label">Menu</div>
  <nav class="nav">
    <?php foreach ($pages as $key => $info): ?>
      <?php $isActive = $page === $key ? 'active' : ''; ?>
      <a class="<?php echo $isActive; ?>" href="?page=<?php echo urlencode($key); ?>">
        <span class="dot"></span>
        <span><?php echo htmlspecialchars($info['title']); ?></span>
      </a>
    <?php endforeach; ?>
  </nav>
</aside>
