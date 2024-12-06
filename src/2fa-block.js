import { __ } from '@wordpress/i18n';
import { registerBlockType } from '@wordpress/blocks';
import { TextControl, PanelBody } from '@wordpress/components';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';

registerBlockType('acemedia/2fa-block', {
    title: __('Two-Factor Authentication Block', 'acemedia-login-block'),
    category: 'common',
    attributes: {
        label: {
            type: 'string',
            default: __('Enter Authentication Code', 'acemedia-login-block'),
        },
        placeholder: {
            type: 'string',
            default: __('Authentication Code', 'acemedia-login-block'),
        },
    },
    edit: function (props) {
        const { attributes, setAttributes } = props;
        const { label, placeholder } = attributes;

        return (
            <div {...useBlockProps()}>
                <InspectorControls>
                    <PanelBody title={__('2FA Block Settings', 'acemedia-login-block')}>
                        <TextControl
                            label={__('Placeholder', 'acemedia-login-block')}
                            value={placeholder}
                            onChange={(value) => setAttributes({ placeholder: value })}
                        />
                    </PanelBody>
                </InspectorControls>
                <TextControl
                    type="text"
                    placeholder={placeholder}
                    value={placeholder}
                    onChange={(value) => setAttributes({ placeholder: value })}
                />
            </div>
        );
    },
    save: () => null, // Server-rendered
});
