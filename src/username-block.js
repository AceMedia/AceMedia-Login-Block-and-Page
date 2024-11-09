import { __ } from '@wordpress/i18n';
import { registerBlockType } from '@wordpress/blocks';
import { TextControl, PanelBody } from '@wordpress/components';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';

registerBlockType("acemedia/username-block", {
    title: __("Username Block", "ace-login-block"),
    category: "common",
    attributes: {
        label: {
            type: "string",
            default: __("Username", "ace-login-block")
        },
        placeholder: {
            type: "string",
            default: __("Username", "ace-login-block")
        }
    },
    edit: function (props) {
        const { attributes, setAttributes } = props;
        const { label, placeholder } = attributes;

        return (
            <div {...useBlockProps()}>
                <InspectorControls>
                    <PanelBody title={__("Username Block Settings", "ace-login-block")}>
                        <TextControl
                            label={__("Placeholder", "ace-login-block")}
                            value={placeholder}
                            onChange={(value) => setAttributes({ placeholder: value })}
                        />
                    </PanelBody>
                </InspectorControls>
                <TextControl
                    type="text"
                    placeholder={placeholder}
                    value={placeholder}
                    onChange={(value) => setAttributes({ placeholder: value })} // Update placeholder attribute on change
                />
            </div>
        );
    },
    save: () => {
        // No save function needed for server-side rendering
        return null;
    },
});