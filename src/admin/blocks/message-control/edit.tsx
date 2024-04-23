/**
 * External dependencies
 */
import { useWooBlockProps } from '@woocommerce/block-templates';
import { useEntityProp } from '@wordpress/core-data';
import parse from 'html-react-parser';

export function Edit({ attributes, context: { postType } } ) {
	const blockProps = useWooBlockProps( attributes );
	let {
		content,
	} = attributes;

	const [ productId ] = useEntityProp< number >(
		'postType',
		'product',
		'id'
	);

	content = content.replace( 'postIdPlaceholder', productId.toString() );

	return (
		<div {...blockProps}>
			<div dangerouslySetInnerHTML={ { __html: parse( content ) } } />
		</div>
	);
}
