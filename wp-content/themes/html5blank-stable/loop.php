<div class="container column is-three-quarters">
	<div class="container">
		<section class="columns">
		<?php $count = 1; ?>
		<?php if (have_posts()): while (have_posts()) : the_post(); ?>

			<!-- article -->
			<article id="post-<?php the_ID(); ?>" class="column is-one-third">
				<a href="<?php the_permalink(); ?>" class="thepost">
					<?php the_post_thumbnail();?>
					<div class="overlay">
						<h2>
							<?php the_title(); ?>
						</h2>
						<span>
							<?php echo get_the_date(); ?>

						</span>
					</div>
				</a>
			</article>
			<!-- /article -->
			<?php 
				$count++;
				if($count % 3 == 1):
			?>
				</section>
				<section class="columns">

			<?php endif; ?>
			<?php endwhile; ?>
		<?php endif; ?>
		</section>
	</div>
</div>