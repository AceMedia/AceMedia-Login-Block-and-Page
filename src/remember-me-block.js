import { __ } from '@wordpress/i18n';
import { registerBlockType } from '@wordpress/blocks';
import { CheckboxControl } from '@wordpress/components';
import { useBlockProps } from '@wordpress/block-editor';

registerBlockType('acemedia/remember-me-block', {
    title: __('Remember Me Block', 'acemedia-login-block'),
    category: 'common',
    attributes: {
        label: {
            type: 'string',
            default: __('Remember Me', 'acemedia-login-block'),
        },
        checked: {
            type: 'boolean',
            default: false,
        },
    },
    edit: ({ attributes, setAttributes }) => {
        const { label, checked } = attributes;

        return (
            <div {...useBlockProps()}>
                <CheckboxControl
                    label={label}
                    checked={checked}
                    onChange={(newChecked) => setAttributes({ checked: newChecked })}
                />
            </div>
        );
    },
    save: () => {
        // No save function needed for server-side rendering
        return null;
    },
});