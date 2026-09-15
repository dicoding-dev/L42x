<?php
	$presenter = new Illuminate\Pagination\BootstrapPresenter($paginator);
?>

<?php if ($paginator->lastPage() > 1): ?>
	<ul class="pagination">
			<?php echo $presenter->render(); ?>
	</ul>
<?php endif; ?>
