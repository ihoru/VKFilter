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
            cwd: 'vendor/components/bootstrap/js/',
            src: ['bootstrap.min.js'],
            dest: 'static/js/'
          },
          {
            expand: true,
            cwd: 'vendor/components/bootstrap/css/',
            src: ['bootstrap.min.css'],
            dest: 'static/css/'
          }
        ]
      }
    },
    less: {
      static: {
        files: {
          'static/css/common.css': 'less/common.less'
        }
      }
    }
  });

  grunt.loadNpmTasks('grunt-contrib-copy');
  grunt.loadNpmTasks('grunt-contrib-clean');
  grunt.loadNpmTasks('grunt-contrib-less');

  grunt.registerTask('default', ['copy', 'less']);

};
