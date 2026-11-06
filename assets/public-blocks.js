(function(wp){
  const h=wp.element.createElement,ServerSideRender=wp.serverSideRender;
  ['sdr/details','sdr/related-events','sdr-scene/details','sdr-scene/related-events'].forEach(function(name){
    wp.blocks.registerBlockType(name,{
      edit:function(props){return h('div',wp.blockEditor.useBlockProps({className:'sdr-detail-block-editor'}),h(ServerSideRender,{block:name,attributes:props.attributes}));},
      save:function(){return null;}
    });
  });
})(window.wp);
