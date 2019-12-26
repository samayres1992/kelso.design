<!doctype html>
<html <?php language_attributes(); ?> class="no-js">
	<head>
		<meta charset="<?php bloginfo('charset'); ?>">
		<title><?php wp_title(''); ?><?php if(wp_title('', false)) { echo ' :'; } ?> <?php bloginfo('name'); ?></title>

		<link href="//www.google-analytics.com" rel="dns-prefetch">
        <link href="<?php echo get_template_directory_uri(); ?>/img/icons/favicon.ico" rel="shortcut icon">
        <link href="<?php echo get_template_directory_uri(); ?>/img/icons/touch.png" rel="apple-touch-icon-precomposed">

		<meta http-equiv="X-UA-Compatible" content="IE=edge,chrome=1">
		<meta name="viewport" content="width=device-width, initial-scale=1.0">
		<meta name="description" content="<?php bloginfo('description'); ?>">
		<link rel="stylesheet" href="/wp-content/themes/html5blank-stable/css/bulma.min.css">
		<link href="/wp-content/themes/html5blank-stable/css/fontawesome.all.css" rel="stylesheet">
		<link href="/wp-content/themes/html5blank-stable/css/lightbox.min.css" rel="stylesheet">
		<?php wp_head(); ?>
		<script>
        // conditionizr.com
        // configure environment tests
        conditionizr.config({
            assets: '<?php echo get_template_directory_uri(); ?>',
            tests: {}
        });
		</script>
		<script type="text/javascript" src="/wp-content/themes/html5blank-stable/js/lightbox-plus-jquery.min.js"></script>
		<script>
			$(document).ready(function() {
				$('.wp-block-image a, .blocks-gallery-item a').attr('data-lightbox', 'slideshow');
			});
		</script>

	</head>
	<body <?php body_class(); ?>>
	<div class="mobile-only">
		<div class="container">
			<a class="logo" href="/"> 
				<h1>kelsey alcorn</h1>
			</a>
			<a class="mobile-right" href="mailto:hello@kelso.design"><span class="icon"><i class="fa fa-envelope"></i></span></a>
		</div>
	</div>
	<section class="main-content columns is-fullheight">
		<aside class="sidebar column is-one-quarter is-narrow-mobile is-fullheight section is-hidden-mobile">
			<section class="sticky">	
				<a class="logo" href="/"> 
					<h1>kelsey alcorn</h1>
				</a>
				<div class="contactinfo">
					<ul class="mail">
						<li>
							<a href="mailto:hello@kelso.design">
								<span class="icon"><i class="fa fa-envelope"></i></span>
							</a>
						</li>
					</ul>
					<ul class="social">
						<li><a href="https://www.linkedin.com/in/ka-design" target="_blank"><i class="fab fa-linkedin"></i></a></li>
						<li><a href="https://www.behance.net/kalcorn113f48d" target="_blank"><i class="fab fa-behance"></i></a></li>
					</ul>
				</div>
			</section>
		</aside>