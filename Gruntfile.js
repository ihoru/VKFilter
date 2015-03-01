/*global module:false*/
module.exports = function(grunt) {

  grunt.initConfig({
    pkg: grunt.file.readJSON('package.json'),
    clean: {
      static: [
        'static/'
      ]
    },
    copy: {
      static: {
        files: [
          {
            expand: true,
            cwd: 'bower_components/jquery-mousewheel/',
            src: ['jquery.mousewheel.min.js'],
            dest: 'static/js/'
          }
        ]
      }
    },
    less: {
      static: {
        files: {
          'static/css/common.css': 'less/common.less',
          'static/css/mobile.css': 'less/mobile.less'
        }
      }
    }
  });

  grunt.loadNpmTasks('grunt-contrib-clean');
  grunt.loadNpmTasks('grunt-contrib-copy');
  grunt.loadNpmTasks('grunt-contrib-less');

  grunt.registerTask('default', ['copy', 'less']);

};
