/*global module:false*/
module.exports = function(grunt) {

  grunt.initConfig({
    pkg: grunt.file.readJSON('package.json'),
    clean: {
      static: [
        'static/'
      ]
    },
    less: {
      static: {
        files: {
          'static/css/common.css': 'less/common.less'
        }
      }
    }
  });

  grunt.loadNpmTasks('grunt-contrib-clean');
  grunt.loadNpmTasks('grunt-contrib-less');

  grunt.registerTask('default', ['less']);

};
