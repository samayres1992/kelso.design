<?php get_header(); ?>
	<!-- section -->
	<div class="columns is-centered">
		<div class="column is-three-quarters">
			<h1><?php the_title(); ?></h1>

			<?php if (have_posts()): while (have_posts()) : the_post(); ?>
			<div class="columns">
			<!-- article -->
				<article class="column" id="post-<?php the_ID(); ?>">
					<?php the_content(); ?>
				</article>
				<!-- /article -->
			</div>
			<?php endwhile; ?>
		<?php endif; ?>
		</div>
	</div>
<?php get_footer(); ?>