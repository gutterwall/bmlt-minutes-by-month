/**
 * BMLT Minutes by Month: a closed month's PDF previews load when the month is opened, so a long archive does not
 * download every PDF with the page. Without this script the File block's link and Download button still work.
 */
document.addEventListener(
	'toggle',
	function ( event ) {
		var month = event.target;
		if ( ! month.open || ! month.classList || ! month.classList.contains( 'bmbm__month' ) ) {
			return;
		}
		month.querySelectorAll( 'object[data-bmbm-src]' ).forEach( function ( embed ) {
			embed.setAttribute( 'data', embed.getAttribute( 'data-bmbm-src' ) );
			embed.removeAttribute( 'data-bmbm-src' );
		} );
	},
	true // "toggle" does not bubble.
);
