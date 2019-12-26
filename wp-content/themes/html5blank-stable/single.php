<?php get_header(); ?>
<div class="column is-three-quarters">
	<section class="container">
		<div class="columns is-centered">
			<div class="column is-10">
				<?php if (have_posts()): while (have_posts()) : the_post(); ?>
				<article id="post-<?php the_ID(); ?>">
					<!-- post thumbnail -->
					<h1 class="title"><a href="<?php the_permalink(); ?>" title="<?php the_title(); ?>"><?php the_title(); ?></a></h1>
					<!-- post details -->
					<?php the_content(); // Dynamic Content ?>
					<div class="tags">
						<?php wp_list_categories(array('title_li' => false, 'style' => false)); ?>
						<?php the_tags( __( '', 'html5blank' ), '', ''); // Separated by commas with a line break at the end ?>
					</div>
					<?php edit_post_link(); // Always handy to have Edit Post Links available ?>
				</article>
				<?php endwhile; ?>
				<?php else: ?>
				<!-- article -->
				<article>
					<h1><?php _e( 'Sorry, nothing to display.', 'html5blank' ); ?></h1>
				</article>
				<?php endif; ?>
			</div>
		</div>
	</section>
</div>

<?php get_footer(); ?>
